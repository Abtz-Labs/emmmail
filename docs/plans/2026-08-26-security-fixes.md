# Security Fixes Plan

**Date:** 2026-08-26
**Status:** pending

## Overview

Address all findings from code review of `index.php` and `tests.php`.

---

## Phase 1: Critical (must fix before anything else)

### 1.1 Account takeover via public `auth` endpoint
- **File:** `index.php` — `actionAuth()`
- **Problem:** Unauthenticated caller can overwrite any account's password/hosts by pointing IMAP/SMTP at their own server
- **Fix:**
  - If account email already exists in DB, require valid session + current password to update
  - Or: refuse to update existing accounts via public `auth`; only allow `login` + a separate `update_account` action behind auth
  - Simplest: if account exists and caller has no session, return error "Account already exists. Please log in to update."

### 1.2 IDOR on every protected action
- **File:** `index.php` — all action functions
- **Problem:** `requireSession()` returns account but no action uses it; all `account_id` params are attacker-controlled
- **Fix:**
  - `requireSession()` already returns the account array — store it in a global or pass it through
  - Every action must derive `account_id` from the session, not from user input
  - Remove `account_id` from request bodies where possible; use session account instead
  - For `sync`: use session's account_id, remove the "pick first account" fallback
  - For `compose`, `drafts`, `draft`, `update_draft`, `delete_draft`, `message_attachments`: use session account_id
  - For `download_attachment`: verify attachment belongs to session's account

---

## Phase 2: High

### 2.1 SMTP/header injection (CRLF)
- **File:** `index.php` — `buildMimeMessage()`, `SmtpClient::send()`
- **Problem:** CRLF in to/subject/filename injects arbitrary SMTP commands or MIME headers
- **Fix:**
  - Add `sanitizeHeader($value)` function: strip `\r`, `\n`, `\0` from all header values
  - Validate all email addresses with `filter_var(FILTER_VALIDATE_EMAIL)` before use
  - Sanitize `subject`, `filename`, `content_type` before inserting into headers
  - In `SmtpClient::send()`, sanitize RCPT TO values

### 2.2 No brute-force protection on `actionLogin`
- **File:** `index.php` — `actionLogin()`
- **Problem:** Never calls `checkRateLimit()`; login endpoint fully exposed to password guessing
- **Fix:**
  - Call `checkRateLimit($email, $ip)` before verifying credentials
  - Call `recordLoginFailure($email, $ip)` on failure
  - Delete stale login_attempts rows on successful login

### 2.3 Session not bound to IP/UA; accepted from URL/body
- **File:** `index.php` — `validateSession()`, `getSessionToken()`
- **Problem:** Leaked token grants full access from any client
- **Fix:**
  - In `validateSession()`, compare stored `ip` and `user_agent` with current request
  - Reject if IP changed (or allow UA change with warning)
  - In `getSessionToken()`: only accept from `X-Session-Token` header and `EMMMAILSESSID` cookie; remove body/URL source
  - Set cookie with `SameSite=Strict; HttpOnly; Path=/`

---

## Phase 3: Medium

### 3.1 SSRF gaps
- **File:** `index.php` — `isSafeHost()`
- **Problem:** DNS-rebinding TOCTOU + IPv4-mapped IPv6 bypass
- **Fix:**
  - Block `::ffff:0:0/96` prefix (IPv4-mapped IPv6)
  - Block `::0:0:0:0/96` (IPv4-compatible IPv6)
  - Note: full TOCTOU fix requires connecting to resolved IP directly (not feasible with stream_socket_client without custom resolver); document as known limitation

### 3.2 Unhandled IMAP exceptions → socket leak
- **File:** `index.php` — `actionSync()`, `actionCompose()`
- **Problem:** Exceptions leave sockets open
- **Fix:**
  - Wrap entire operations in try/finally blocks
  - Always call `$imap->logout()` / `$smtp->quit()` in finally

### 3.3 `getEncryptionKey()` concurrent append race
- **File:** `index.php` — `getEncryptionKey()`
- **Problem:** Concurrent requests generate two keys; passwords become undecryptable
- **Fix:**
  - Use `flock(LOCK_EX)` on `.env` file
  - Re-read after locking to check if key was already written
  - Write only if key line not present

### 3.4 Unbounded message buffering (memory DoS)
- **File:** `index.php` — `fetchMessageBody()`, `readRaw()`
- **Problem:** Large messages exhaust PHP memory
- **Fix:**
  - Add `EMMMAIL_MAX_MESSAGE_SIZE` constant (default 10MB)
  - Check size in `readRaw()` and throw if exceeded
  - Skip oversized messages in sync

### 3.5 Bcc leaked to all recipients
- **File:** `index.php` — `buildMimeMessage()`
- **Problem:** Bcc header appears in delivered message
- **Fix:**
  - Remove Bcc from MIME header block
  - Only use Bcc addresses in `RCPT TO` SMTP commands

### 3.6 Orphaned attachment files on disk
- **File:** `index.php` — `storeAttachments()`, sync cascade delete
- **Problem:** Files not cleaned up when messages/accounts deleted
- **Fix:**
  - Add `deleteAttachmentFiles($accountId, $messageId)` helper
  - Call on message delete and account delete
  - Check `file_put_contents` return value; roll back DB row on failure

---

## Phase 4: Low

### 4.1 CSRF protection
- **Fix:** Require `X-Session-Token` header for all state-changing actions (compose, draft CRUD, sync, logout); disallow cookie-only auth for POST actions

### 4.2 `download_attachment` path containment
- **Fix:** Verify resolved `file_path` starts with `EMMMAIL_ATTACH_DIR`

### 4.3 Content-Type header sanitization
- **Fix:** Sanitize `content_type` before storing in DB and before sending in response header

### 4.4 Email input validation
- **Fix:** Add `filter_var(FILTER_VALIDATE_EMAIL)` + control-char rejection in `actionAuth()` and `actionLogin()`

### 4.5 Session cleanup
- **Fix:** Add periodic cleanup: `DELETE FROM sessions WHERE last_activity_at < datetime('now', '-1 day')` — run on first request after midnight (or on sync)

---

## Phase 5: Test fixes

### 5.1 Real-server tests missing session tokens
- Add session auth to `testSyncEndpointReal()` and `testDraftEndpointReal()`

### 5.2 Draft-subject assertion
- Fix expected value in `testDraftEndpointReal()`

### 5.3 Idempotency test false pass
- Reset static `$done` flag between runs, or test differently

### 5.4 SSRF tests through endpoints
- Add tests that verify `sync`/`auth` reject private IPs in host params

### 5.5 IP rate limit test
- Add test for `EMMMAIL_IP_MAX_ATTEMPTS`

### 5.6 New tests for security fixes
- Test account takeover prevention
- Test IDOR prevention (cross-account access blocked)
- Test CRLF injection rejection
- Test login rate limiting
- Test session IP binding
- Test path containment on attachment download
- Test session cleanup

---

## Execution Order

1. Phase 1 (Critical) — first
2. Phase 2 (High) — second
3. Phase 3 (Medium) — third
4. Phase 4 (Low) — fourth
5. Phase 5 (Tests) — after each phase, add corresponding tests
6. Run full test suite after each phase
7. Update README.md with security notes

## Verification

After all fixes:
```bash
php tests.php  # all green
```
