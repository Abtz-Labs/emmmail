# Accounts API Endpoints Plan

**Date:** 2026-08-26
**Status:** done

## Overview

Implement 5 account management endpoints. The app is a single-user, multi-account email client: each account is independent, the user can switch between them. `detect_server` and `test_connection` are public (setup wizard); the rest require a session.

## Trust Model

- **Single-user, multi-account.** No `owner_id` column. Each account is a standalone email identity.
- **Account switcher:** `add_account` creates the account and returns a `session_token` so the client can immediately switch to it.
- **`list_accounts`** returns all accounts (for the switcher UI). Never returns password fields.
- **`delete_account`** can only delete the current session's account (IDOR prevention).
- **`detect_server`/`test_connection`** are public for the setup wizard, with per-IP rate limiting.

---

## Phase 1: `list_accounts`

**Action:** `list_accounts`
**Input:** none (session required)
**Output:** All accounts (for account switcher UI).

**Query:**
```sql
SELECT id, email, display_name, imap_host, smtp_host, is_active, last_sync_at, created_at
FROM accounts
WHERE is_active = 1
ORDER BY created_at DESC, id DESC
```

Note: Never return `password_encrypted`, `password_iv`, `password_tag`, `imap_port`, `imap_tls`, `smtp_port`, `smtp_tls`.

**Timestamp normalization:** SQLite `datetime('now')` produces `"YYYY-MM-DD HH:MM:SS"`. Convert to ISO `"YYYY-MM-DDTHH:MM:SSZ"` in PHP before responding.

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "accounts": [
      { "id": 1, "email": "you@example.com", "display_name": "You",
        "imap_host": "imap.migadu.com", "smtp_host": "smtp.migadu.com",
        "is_active": 1, "last_sync_at": "2026-08-26T10:00:00Z", "created_at": "2026-08-25T08:00:00Z" }
    ]
  }
}
```

**Tests:**
- Returns accounts list
- Does not include password fields or port/TLS fields
- Returns accounts sorted by created_at DESC
- Rejects invalid session

---

## Phase 2: `add_account`

**Action:** `add_account`
**Input:** Same as `auth` — `preset` or manual IMAP/SMTP config, `email`, `password`
**Output:** Account ID + session token + IMAP/SMTP test results.

**Logic:**
1. Require session.
2. Validate email (lowercase, trim). Validate email format.
3. Resolve preset or validate manual config fields.
4. **Port validation:** Cast to int, reject if outside 1..65535.
5. **TLS validation:** Cast to int, reject if not in {0, 1, 2}.
6. Rate limit check (`checkRateLimit($email, $ip)`).
7. Test IMAP + SMTP via `testImapSmtp()` helper (extracted from `actionAuth`).
8. If both fail → `recordLoginFailure($email, $ip)` + `fail()`.
9. Check if email already exists → `fail('Account already exists. Use auth to update.', 409)`.
10. Encrypt password, INSERT into accounts.
11. Create session for the new account (`createSession()`).
12. Return account_id + session_token + connection test results.

**SQL:**
```sql
INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
```

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "account_id": 2,
    "session_token": "abc123...",
    "email": "other@example.com",
    "imap": { "ok": true, "error": "", "folders": [...] },
    "smtp": { "ok": true, "error": "" }
  }
}
```

**Tests:**
- Adds new account with valid credentials (env-var gated, skips if no creds)
- Returns session_token for the new account
- Fails for duplicate email (409)
- Fails for missing email/password
- Fails for unknown preset
- Fails for invalid port (0, 70000, -1)
- Fails for invalid TLS (3, -1)
- Requires session (not public)
- IMAP-only success still creates account
- SMTP-only success still creates account
- Records login failure when both fail

---

## Phase 3: `delete_account`

**Action:** `delete_account`
**Input:** none (deletes the current session's account)
**Output:** Confirmation of deletion.

**Logic:**
1. Get `currentAccountId()` from session.
2. Load account row (verify it exists).
3. Begin transaction.
4. Delete sessions: `DELETE FROM sessions WHERE account_id = ?` (this destroys the current session too).
5. Delete account: `DELETE FROM accounts WHERE id = ?`. CASCADE handles folders, threads, messages, drafts, attachments rows.
6. Commit transaction.
7. Delete attachment files from disk (`deleteAccountAttachments()` — after DB commit, so partial failure doesn't leave orphaned DB rows).
8. Emit response. Note: the current session is already destroyed, so the client must handle the "logged out" state.

**SQL:**
```sql
BEGIN TRANSACTION;
DELETE FROM sessions WHERE account_id = ?;
DELETE FROM accounts WHERE id = ?;
COMMIT;
```

**Response shape:**
```json
{ "ok": true, "data": { "deleted": true, "account_id": 1 } }
```

**Tests:**
- Deletes current account and all associated data
- Deletes attachment files from disk
- Destroys all sessions for the account
- After deletion, session is invalid
- Fails when no account in session
- Cannot delete another account (no `account_id` param — always current)

---

## Phase 4: `detect_server`

**Action:** `detect_server`
**Input:** `email` (required)
**Output:** Detected IMAP/SMTP server settings.

**Visibility:** Public (for setup wizard). Rate limited per IP.

**Logic:**
1. Validate email format.
2. Extract domain (lowercase, trim).
3. Check provider presets by domain mapping:
   - `gmail.com`, `googlemail.com` → `gmail` preset
   - `yahoo.com`, `yahoo.co.uk`, `yahoo.co.jp`, `ymail.com` → `yahoo` preset
   - `outlook.com`, `hotmail.com`, `live.com`, `msn.com` → `outlook` preset
   - `icloud.com`, `me.com`, `mac.com` → `icloud` preset
   - `migadu.com` → `migadu` preset
4. If no preset match, try MX lookup:
   - `dns_get_record($domain, DNS_MX)` — trim trailing dots from targets.
   - If MX targets `*.google.com` or `*.googlemail.com` → `gmail` preset.
   - If MX targets `*.outlook.com` or `*.office365.com` → `outlook` preset.
   - If MX targets `*.yahoodns.net` or `*.yahoo.com` → `yahoo` preset.
   - If MX targets `*.migadu.com` → `migadu` preset.
   - Guard: if `dns_get_record` returns `false` or empty, skip MX step.
5. If still no match, return generic IMAP/SMTP hostnames:
   - `imap.{domain}:993` (SSL) and `smtp.{domain}:587` (STARTTLS)
6. Return the detected config (without testing connectivity).

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "detected": true,
    "preset": "migadu",
    "imap_host": "imap.migadu.com",
    "imap_port": 993,
    "imap_tls": 1,
    "smtp_host": "smtp.migadu.com",
    "smtp_port": 465,
    "smtp_tls": 1
  }
}
```

Or when falling back to generic:
```json
{
  "ok": true,
  "data": {
    "detected": false,
    "preset": null,
    "imap_host": "imap.example.com",
    "imap_port": 993,
    "imap_tls": 1,
    "smtp_host": "smtp.example.com",
    "smtp_port": 587,
    "smtp_tls": 2
  }
}
```

**Tests:**
- Detects gmail from `user@gmail.com`
- Detects gmail from `user@googlemail.com`
- Detects outlook from `user@hotmail.com`
- Detects icloud from `user@me.com`
- Detects migadu from `user@migadu.com`
- Falls back to generic for unknown domain
- Fails for invalid email
- Returns `detected: true` for known providers, `false` for fallback
- Handles `dns_get_record` returning false gracefully
- Domain is case-insensitive (`USER@GMAIL.COM`)

---

## Phase 5: `test_connection`

**Action:** `test_connection`
**Input:** Same as `auth` — `preset` or manual IMAP/SMTP config, `email`, `password`
**Output:** IMAP/SMTP test results. Does NOT save the account.

**Visibility:** Public (for setup wizard). Rate limited per email+IP.

**Logic:**
1. Validate email (lowercase, trim). Validate email format.
2. Resolve preset or validate manual config fields.
3. **Port validation:** Cast to int, reject if outside 1..65535.
4. **TLS validation:** Cast to int, reject if not in {0, 1, 2}.
5. Rate limit check (`checkRateLimit($email, $ip)`).
6. Test IMAP + SMTP via `testImapSmtp()` helper.
7. If both fail → `recordLoginFailure($email, $ip)` + `fail()`.
8. Return results (same shape as `auth` response, minus `account_id`).

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "email": "you@example.com",
    "imap": { "ok": true, "error": "", "folders": ["INBOX", "Sent", ...] },
    "smtp": { "ok": true, "error": "" }
  }
}
```

**Tests:**
- Returns IMAP/SMTP results without saving (env-var gated)
- Returns partial results when only IMAP succeeds
- Returns partial results when only SMTP succeeds
- Fails when both fail
- Fails for missing email/password
- Fails for invalid port
- Fails for invalid TLS
- Account count unchanged after test
- Records login failure on both-fail

---

## Shared Infrastructure

### `testImapSmtp()` helper

Extract from `actionAuth()` to avoid duplicating the connect+login logic in `add_account`, `test_connection`, and `auth`.

```php
function testImapSmtp(string $imapHost, int $imapPort, int $imapTls, string $smtpHost, int $smtpPort, int $smtpTls, string $email, string $password): array
```

Returns:
```php
[
    'imap' => ['ok' => true/false, 'error' => '', 'folders' => [...]],
    'smtp' => ['ok' => true/false, 'error' => ''],
]
```

Logic (same as `actionAuth` lines 1916-1940):
1. Try IMAP connect + login + listFolders + logout. Catch → error.
2. Try SMTP connect + login + quit. Catch → error.
3. Return results.

### `validatePortAndTls()` helper

```php
function validatePortAndTls(int $port, int $tls): void
{
    if ($port < 1 || $port > 65535) fail('Port must be between 1 and 65535.');
    if ($tls < 0 || $tls > 2) fail('TLS mode must be 0 (none), 1 (SSL), or 2 (STARTTLS).');
}
```

### `detectServersByEmail()` helper

```php
function detectServersByEmail(string $email): array
```

Returns the detected config array (preset name + IMAP/SMTP settings). Used by `detect_server` endpoint.

### Router changes

Add to `handleRequest()` switch:
```php
case 'list_accounts': actionListAccounts(); break;
case 'add_account': actionAddAccount(); break;
case 'delete_account': actionDeleteAccount(); break;
case 'detect_server': actionDetectServer(); break;
case 'test_connection': actionTestConnection(); break;
```

Add `detect_server` and `test_connection` to `$publicActions`:
```php
$publicActions = ['login', 'session', 'auth', 'detect_server', 'test_connection'];
```

### `actionAuth()` refactor

After extracting `testImapSmtp()`, refactor `actionAuth()` to call it. Verify the refactor preserves the exact order: rate limit → test → `recordLoginFailure` on both-fail → DB.

---

## Implementation Order

| Phase | Endpoint | LOC est. | Dependencies |
|-------|----------|----------|--------------|
| 1 | `list_accounts` | ~25 | none |
| 2 | `add_account` | ~60 | `testImapSmtp()`, `validatePortAndTls()` |
| 3 | `delete_account` | ~35 | `deleteAccountAttachments()` (exists) |
| 4 | `detect_server` | ~70 | `detectServersByEmail()`, `getPresets()` |
| 5 | `test_connection` | ~40 | `testImapSmtp()`, `validatePortAndTls()` |
| — | `testImapSmtp()` refactor | ~30 | extract from `actionAuth()` |
| — | `actionAuth()` refactor | ~20 | use `testImapSmtp()` |

Total: ~280 LOC implementation + ~200 LOC tests.

## Verification

After each phase:
1. Run `php tests.php` — all new tests pass, 0 regressions.
2. Run full suite — confirm total count increases.
3. Update README.md with new endpoint docs.
4. Update docs/memory.md.
