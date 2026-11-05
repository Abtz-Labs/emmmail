# Provider storage usage on the Settings page

**Status:** in progress
**Date:** 2026-11-05

## Goal

Show the account's storage usage on the Settings page, the way the Gmail iOS app does:
a percentage, a bar, and "X of Y". No "Get storage" or "Clean up space" upsell links.

Provider-neutral by design:

1. **RFC 2087 QUOTA** through `imap_get_quotaroot()`. Gmail advertises `QUOTA`; so do
   many Dovecot/Cyrus servers. This yields a real usage and limit.
2. **Local estimate** when the server does not report QUOTA: sum the `messages.size`
   column of the header cache. This is an approximation and the card must say so.

The SQLite **file** size is not used: it stores cached headers and snippets, never bodies,
and clearing the cache resets it while the provider's mailbox does not change.

## Out of scope

- Provider APIs (Google Drive/Photos, Microsoft Graph). Mailbox credentials cannot reach them.
- Scanning every folder over IMAP for a used-only number.
- Any "buy storage" or "clean up" action.
- Caching the QUOTA response between page loads.

## Cost note

Settings currently opens no connection when the folder map is already saved. After this
change it opens one per GET: TCP probe (bounded 5s), TLS, LOGIN, GETQUOTAROOT. This is
deliberate for a live provider number; README says so. On an unreachable host the page
stalls for the probe, then falls back to the cache estimate with a "could not reach"
note.

## Design

### 1. Normalisation (pure functions, unit-testable)

`quota_storage_from_response($raw): ?array`

- Accepts the raw `imap_get_quotaroot()` result.
- ext-imap returns a nested resource array (`STORAGE => ['usage','limit']`) plus flat
  `usage`/`limit` siblings. Only the nested array is authoritative; the flat keys are
  integers, so the scan must skip non-array values rather than assume every value has
  `usage` (which would be a TypeError on `MESSAGE`).
- Lookup order: exact `STORAGE`, then a key that starts with `STORAGE` case-insensitively
  (for resource variants). An entry without a numeric `usage` is skipped, so a bad
  `STORAGE` does not hide a usable variant.
- RFC 2087 reports STORAGE in **1024-octet units**, so multiply `usage` and `limit` by 1024.
- `limit <= 0` means no limit/unknown: return `null` for the limit, not 0.
- Missing/unusable `usage` returns `null` (the whole response is discarded).
- An empty array (server answered OK with no quota data) returns `null`.
- Returns `['used' => int, 'limit' => ?int]` in bytes.

`format_bytes(int $bytes): string`

- 1024-based units: B, KB, MB, GB, TB, PB.
- 2 decimals below 10, 1 below 100, 0 at 100 and above; trim trailing zeros.
- Promote after rounding when the rounded value reaches 1024, so `1_048_575` bytes is
  `1 MB`, never `1,024 KB`.
- Examples: `1024` -> `1 KB`, `1536` -> `1.5 KB`, `15360 * 1024` -> `15 MB`,
  `3.88 * 1024^3` -> `3.88 GB`.

`quota_percent(int $used, ?int $limit): ?int`

- `null` when the limit is missing or not positive.
- Otherwise `round(used / limit * 100)` clamped to 0..100. Over-quota reads 100.

### 2. `MailImap::storageQuota(string $mailbox = 'INBOX'): ?array`

- Returns `null` when there is no connection or `imap_get_quotaroot` is absent.
- Drains `imap_errors()` before and after the call. An unsupported server queues
  `Quota not available on this IMAP server`; an undrained error is dumped at PHP
  shutdown (same rule as failed CREATE/DELETE). This applies to the common
  no-capability path, which sends no command at all.
- Calls `@imap_get_quotaroot($this->conn, $mailbox)`. The connection is half-open and
  has no mailbox selected; GETQUOTAROOT is valid there. The mailbox is sent verbatim,
  no server prefix.
- Returns `null` when the function returns false or the response has no STORAGE.
- Otherwise returns `quota_storage_from_response($raw)`.
- Does not SELECT, does not LIST. One command at most.

### 3. `db_cached_storage(string $email): array`

Returns `['used' => int, 'cached' => int]`.

- `used`: `COALESCE(SUM(size), 0)` from `messages` for that user.
- `cached`: `COUNT(*)` of those rows.
- Any DB failure returns zeros.
- No `folder_state.total` denominator: it compares bytes against a message count, is
  last-known STATUS, and omits never-opened folders. The note simply says which folders
  the estimate covers.

### 4. `handle_settings()`

- Build one connection with `imap_for_session(false)`, so a failed connection does **not**
  redirect/log out on the Settings page.
- If folder detection is still needed and `$imap` exists, call `detect_folder_map($imap)`
  to reuse the connection. When `$imap` is null, **skip detection**: the map modal opens
  empty and the page stays usable. This is the one deliberate behaviour change: a
  read-only probe must never end the session. It also removes the second TCP probe.
- Ask `storageQuota('INBOX')`; disconnect afterwards.
- Data passed to the view:
  - quota: `['mode' => 'quota'] + $quota`
  - cache with a live connection but no QUOTA: `['mode' => 'cache', 'reason' => 'unsupported']`
  - cache with no connection: `['mode' => 'cache', 'reason' => 'unreachable']`
- The cache card's `cached_messages` reuses `$storage['cached']` when in cache mode, so
  the same table is not counted twice.

### 5. `view_settings()` / `view_storage_card()`

A card above **Local cache**, outside the settings form, using the existing
`card cache-card` classes plus `storage-card`.

Quota mode, with a limit:

```
Storage
27% of 15 GB used
[=========-------------------------------]
4 GB of 15 GB
Reported by your mail provider.
```

- `role="progressbar"`, `aria-valuenow/min/max`, accessible label.
- Bar fill uses `var(--accent)` on `var(--surface-3)`, so both themes work.
- Inline `style="width:27%"` is allowed by the existing `style-src 'self' 'unsafe-inline'`.
- `.storage-card .settings-sub:first-child { margin-top:0; }` removes the 26px heading
  margin inside the 24px card.

Quota mode, no limit: `X used`, no bar, note says the server reports no limit.

Cache mode:

```
Storage
About 35.9 MB used
Your provider does not report usage.
Size inferred from 561 messages currently cached.
```

- `reason = unreachable`: the first note line is `Could not reach the mail server.` instead
  of the provider line, so a connection failure is never blamed on the provider.
- Zero cached rows: `No message headers cached yet` plus the cause line; no size line.
- No bar: there is no total to measure against.
- No "Get storage" / "Clean up space" links anywhere.

If `storage` is absent from the render data, render no card (keeps existing direct
`view_settings()` test fixtures valid).

### 6. Test stub

`tests/_imap_stub.php`:

- Scenario keys `quota` (default `null`) and `quota_fail` (default `false`).
- The greeting is written in the `accept` branch, which currently never calls
  `scenario()`. It must, or QUOTA is advertised only on the command reply, too late.
- When `quota` is set, advertise `QUOTA` in the greeting and the `CAPABILITY` response.
- Answer `GETQUOTAROOT` with `* QUOTAROOT <mailbox> "ROOT"` and
  `* QUOTA "ROOT" (STORAGE <usage> <limit>)`, then tagged OK.
- `quota_fail` answers NO.
- Comment the c-client capability latch: capabilities are read once per connection.
  Every quota test must write its scenario **before** `connect()` and then `resetLog()`.

### 7. Tests

Unit:

- `format_bytes()`: 0, 1023, 1024, 1536, 1 MB, 1,048,575 (promotion), 15 GB, 3.88 GB.
- `quota_storage_from_response()`: the exact ext-imap shape (nested STORAGE plus flat
  siblings that are ignored) and a `MESSAGE` array, uppercase/lowercase STORAGE, prefix
  variant, KB conversion, limit 0 -> null, missing usage -> null, no STORAGE -> null,
  empty array -> null.
- `quota_percent()`: normal, 0, over 100 (clamped), no limit.
- `db_cached_storage()`: sums rows per user only, counts rows, ignores another user's
  rows, returns zeros on an empty database.
- `view_settings()`: quota card text/bar/note; quota with no limit; cache card text/note;
  unreachable with and without cached rows; zero-cache text; no upsell strings; no card
  when `storage` is absent.
- Source-level guard: `handle_settings()` uses `imap_for_session(false)`, calls
  `storageQuota('INBOX')`, reuses the connection for `detect_folder_map($imap)`, and never
  calls the bare `detect_folder_map()` that would redirect to logout.

IMAP (stub):

- QUOTA server: `storageQuota()` returns bytes from a scenario `{usage:1024, limit:2048}`
  => `1048576`/`2097152`, and the billable trace is exactly `['GETQUOTAROOT INBOX']`
  (no SELECT/LIST/STATUS).
- Unsupported server (no QUOTA capability): returns null, sends no command, and leaves
  `imap_errors()` drained.
- Server NO: returns null, and leaves `imap_errors()` drained.

### 8. Documentation

- `README.md`: a Features bullet for storage display; a note in the Storage section that
  the card appears when the server supports RFC 2087, that Gmail and many self-hosted
  servers do, that iCloud/Outlook/Yahoo do not, that the fallback is a cache estimate, and
  that opening Settings now costs one IMAP connection when quota is checked.
- `docs/knowledge/imap.md`: QUOTA is safe on a half-open connection; PHP returns KB and
  injects flat `usage`/`limit`; an OK with no quota data yields `[]`; the no-capability
  path queues an error that must be drained; capability is latched per connection.
- `docs/knowledge/testing.md`: the stub writes its greeting at accept, and c-client
  latches capabilities per connection, so a capability scenario needs a fresh `connect()`.
- `docs/memory.md`: plan status line.

## Verification

1. `php tests/unit.php` green.
2. `php tests/imap.php` green (stub only, no credentials).
3. `php tests/run.php` green.
4. Browser pass (owed, not in this session): Gmail account shows the bar; an account on
   a server without QUOTA shows the cached estimate and the note; both themes; phone width.
