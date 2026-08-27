# Settings API Endpoints Plan

**Date:** 2026-08-26
**Status:** done

## Overview

Implement 3 endpoints: `get_settings`, `update_settings`, `update_signature`. All settings are per-account — different accounts may belong to different people with different preferences.

---

## Schema (new table)

Add to `EMMMAIL_SCHEMA`:

```sql
CREATE TABLE IF NOT EXISTS settings (
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (account_id, name)
);
```

Notes:
- `account_id` — all settings scoped per account (finding #1).
- `name` instead of `key` — avoids SQLite keyword fragility (finding #13).
- `value TEXT NOT NULL` — prevents stray NULLs (finding #12).
- Composite primary key `(account_id, name)` — one row per setting per account.

---

## Settings keys

| Key | Type | Default | Written by |
|-----|------|---------|------------|
| `signature` | string | `''` | `update_signature` only |
| `theme` | string | `'dark'` | `update_settings` |
| `show_remote_images` | bool | `false` | `update_settings` |
| `sync_on_load` | bool | `true` | `update_settings` |

Notes:
- `update_notifications` and `update_url` from the old version are omitted — no update-check feature exists in the current codebase.
- Booleans stored as `'1'`/`'0'` strings in SQLite.
- `signature` is intentionally excluded from `update_settings` whitelist — must use `update_signature`.

---

## Phase 1: Schema + `getSettingsArray()` helper

Add the `settings` table to `EMMMAIL_SCHEMA`.

### `getSettingsArray()` helper

```php
function getSettingsArray(PDO $pdo, int $accountId): array
{
    $defaults = [
        'signature' => '',
        'theme' => 'dark',
        'show_remote_images' => false,
        'sync_on_load' => true,
    ];
    $rows = fetchAll($pdo, 'SELECT name, value FROM settings WHERE account_id = ?', [$accountId]);
    foreach ($rows as $r) {
        $k = $r['name'];
        if (!array_key_exists($k, $defaults)) continue;    // finding #6: skip unknown keys
        $v = $r['value'];
        if (in_array($k, ['show_remote_images', 'sync_on_load'], true)) {
            $v = $v === '1';                                 // finding #2: explicit bool coercion
        }
        $defaults[$k] = $v;
    }
    return $defaults;
}
```

Notes:
- Takes `$accountId` parameter — per-account scoping (finding #1).
- Whitelist via `array_key_exists($k, $defaults)` — unknown keys skipped (finding #6).
- Explicit `$v === '1'` for bools — no `(bool)` cast (finding #2).

**Tests:**
- Schema includes settings table
- `getSettingsArray` returns defaults when table is empty
- `getSettingsArray` reads stored values
- Boolean values are cast to PHP bool
- Unknown stored keys are ignored

---

## Phase 2: `get_settings`

**Action:** `get_settings`
**Input:** none (session required)
**Output:** All settings for the current account with defaults applied.

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "settings": {
      "signature": "",
      "theme": "dark",
      "show_remote_images": false,
      "sync_on_load": true
    }
  }
}
```

**Tests:**
- Returns default settings when none stored
- Returns stored settings
- Boolean values are actual booleans (not strings)
- Settings are per-account (different accounts have different settings)
- Rejects invalid session

---

## Phase 3: `update_settings`

**Action:** `update_settings`
**Input:** Any subset of: `theme`, `show_remote_images`, `sync_on_load`
**Output:** Updated settings.

**Logic:**
1. Read input. If no whitelisted keys present → `fail('No fields to update.')` (finding #4).
2. For each key present in input:
   - `theme`: validate against `['dark', 'light']`. Invalid → `fail('Invalid theme value. Must be dark or light.')` (finding #5).
   - `show_remote_images`/`sync_on_load`: normalize explicitly (finding #2):
     ```php
     $v = in_array($v, [true, 1, '1', 'true', 'on'], true) ? '1' : '0';
     ```
   - Cast all values to `(string)` before processing (finding #3).
3. UPSERT: `INSERT INTO settings (account_id, name, value) VALUES (?, ?, ?) ON CONFLICT(account_id, name) DO UPDATE SET value = excluded.value`.
4. Return full settings via `getSettingsArray()`.

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "settings": {
      "signature": "",
      "theme": "light",
      "show_remote_images": true,
      "sync_on_load": true
    }
  }
}
```

**Tests:**
- Updates theme
- Rejects invalid theme value (finding #5)
- Updates boolean settings with true/1/'1'/'true'/'on'
- Updates boolean settings with false/0/'0'/'false'/'off'
- Partial update (only one key) doesn't clear others
- Returns full settings after update
- Fails with no valid fields (finding #4)
- Unknown keys in input are ignored (finding #9)
- `signature` cannot be set via `update_settings` (finding #8)
- Rejects invalid session

---

## Phase 4: `update_signature`

**Action:** `update_signature`
**Input:** `signature` (required, string, max 10000 chars)
**Output:** Updated signature value.

**Logic:**
1. Read `signature` from input. If key not present → `fail('Missing required field: signature.')` (finding #10).
2. Cast to `(string)` (finding #3).
3. Check length with `mb_strlen`. If > 10000 → `fail('Signature too long (max 10000 characters).')` (finding #7: return error, don't silently truncate).
4. UPSERT into settings with name `'signature'`.
5. Return the signature.

**Response shape:**
```json
{ "ok": true, "data": { "signature": "-- \nSent from Emmmail" } }
```

Note: returns `data.signature` (scalar) — same shape as `get_settings` returns `data.settings.signature`. The signature endpoint is a focused write path; the response mirrors the field being written (finding #11: defensible for a single-field endpoint).

**Tests:**
- Sets signature
- Updates existing signature
- Clears signature (explicit empty string)
- Fails when signature key missing (finding #10)
- Rejects oversized signature (> 10000 chars) (finding #7)
- Handles non-string input (number, array) without 500 (finding #3)
- Rejects invalid session

---

## Deferred (future phase)

- POST method enforcement for write endpoints (finding #14) — applies to all write endpoints, not just settings. Defer to a global middleware approach.
- Signature sanitization contract (finding #15) — document that frontend must HTML-escape at render. No server-side sanitization needed for plain-text storage.

---

## Implementation Order

| Phase | What | LOC est. | Dependencies |
|-------|------|----------|--------------|
| 1 | Schema + `getSettingsArray()` | ~20 | none |
| 2 | `get_settings` | ~10 | Phase 1 |
| 3 | `update_settings` | ~35 | Phase 1 |
| 4 | `update_signature` | ~20 | Phase 1 |

Total: ~85 LOC implementation + ~120 LOC tests.

## Router changes

Add to `handleRequest()` switch:
```php
case 'get_settings': actionGetSettings(); break;
case 'update_settings': actionUpdateSettings(); break;
case 'update_signature': actionUpdateSignature(); break;
```

All 3 are authenticated (not in `$publicActions`).

## Verification

After each phase:
1. Run `php tests.php` — all new tests pass, 0 regressions.
2. Run full suite — confirm total count increases.
3. Update README.md with new endpoint docs.
4. Update docs/memory.md.
