# Core API Endpoints Plan

**Date:** 2026-08-26
**Status:** done

## Overview

Implement 7 endpoints that complete the message management API. Phases 1-3 are read-only (SQLite). Phases 4-5 involve writes that must sync to IMAP for moves/deletes. Phase 6 adds FTS5 search via a versioned migration.

---

## Phase 0: `list_folders` (prerequisite)

**Action:** `list_folders`
**Input:** none (account from session)
**Output:** All folders for the account with role, unread/total counts.

**Query:**
```sql
SELECT id, name, role, unread_count, total_count
FROM folders WHERE account_id = ?
ORDER BY
  CASE role
    WHEN 'inbox' THEN 1 WHEN 'sent' THEN 2 WHEN 'drafts' THEN 3
    WHEN 'archive' THEN 4 WHEN 'junk' THEN 5 WHEN 'trash' THEN 6
    ELSE 7
  END, name
```

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "folders": [
      { "id": 1, "name": "INBOX", "role": "inbox", "unread_count": 5, "total_count": 120 }
    ]
  }
}
```

**Tests:**
- Returns folders sorted by role priority
- Returns empty array when no folders synced
- Cross-account blocked (account from session)

---

## Phase 1: `list_messages`

**Action:** `list_messages`
**Input:** `folder` (required), `cursor` (optional, format `"date|id"`), `limit` (optional, default 50, max 100)
**Output:** Paginated message list, newest first.

**Cursor pagination (composite keyset):**
Cursor encodes `date|id` of the last item on the previous page. This avoids the bug where `id < ?` with `ORDER BY date DESC` drops rows when ID order diverges from date order (imported messages).

**Query (first page):**
```sql
SELECT id, uid, message_id, subject, from_address, from_name,
       to_addresses, date, is_read, is_starred, has_attachments, thread_id,
       SUBSTR(text_body, 1, 200) AS preview
FROM messages
WHERE account_id = ? AND folder_name = ?
ORDER BY date DESC, id DESC
LIMIT ?
```

**Query (subsequent pages):**
```sql
... WHERE account_id = ? AND folder_name = ?
  AND (date < ? OR (date = ? AND id < ?))
ORDER BY date DESC, id DESC
LIMIT ?
```

**`has_more` computation:** Fetch `limit + 1` rows. If `count > limit`, `has_more = true`; return only `limit` rows. `next_cursor` = `"date|id"` of the last returned row.

**`limit` validation:** Cast to int, clamp to `[1, 100]`.

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "messages": [
      { "id": 1, "uid": 42, "subject": "Hi", "from_address": "a@b.com",
        "from_name": "Alice", "to_addresses": ["x@y.com"], "date": "2026-08-26T10:00:00Z",
        "is_read": 0, "is_starred": 0, "has_attachments": 1, "thread_id": 5,
        "preview": "Hey, check this out..." }
    ],
    "has_more": true,
    "next_cursor": "2026-08-25T09:00:00Z|38"
  }
}
```

**Tests:**
- Returns messages in folder, newest first
- Pagination via cursor: second page returns older messages, no duplicates
- Cursor with old-imported high-ID message doesn't get dropped
- Empty folder returns empty array, `has_more` false
- Missing `folder` fails
- Limit clamped: `limit=0` → 1, `limit=500` → 100
- `to_addresses` is JSON-decoded array in response
- `preview` is truncated to 200 chars

---

## Phase 2: `get_message`

**Action:** `get_message`
**Input:** `message_id` (required, DB pk)
**Output:** Full message (no `raw_headers`) + attachments list.

**Query message:**
```sql
SELECT id, account_id, folder_name, uid, message_id, in_reply_to, refs,
       subject, from_address, from_name, to_addresses, cc_addresses,
       date, text_body, html_body, thread_id, is_read, is_starred,
       has_attachments, size, created_at
FROM messages WHERE id = ? AND account_id = ?
```

Note: `raw_headers` excluded from SELECT (internal data, not for API consumers).

**Query attachments:**
```sql
SELECT id, filename, content_type, size
FROM attachments WHERE message_id = ? AND account_id = ?
ORDER BY id
```

Note: `AND account_id = ?` for defense-in-depth (matches `actionMessageAttachments` pattern at `index.php:2319`).

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "message": {
      "id": 1, "folder_name": "INBOX", "uid": 42,
      "message_id": "<abc@mail.example.com>", "in_reply_to": "<prev@mail.example.com>",
      "refs": "<orig@mail.example.com>",
      "subject": "Re: Hello", "from_address": "a@b.com", "from_name": "Alice",
      "to_addresses": ["x@y.com"], "cc_addresses": ["z@w.com"],
      "date": "2026-08-26T10:00:00Z",
      "text_body": "Full plain text...", "html_body": "<p>Full HTML...</p>",
      "thread_id": 5, "is_read": 1, "is_starred": 0,
      "has_attachments": 1, "size": 45000, "created_at": "2026-08-26T12:00:00"
    },
    "attachments": [
      { "id": 1, "filename": "report.pdf", "content_type": "application/pdf", "size": 32000 }
    ]
  }
}
```

**Tests:**
- Returns full message with text_body and html_body
- Returns attachments list ordered by id
- JSON-decodes to_addresses and cc_addresses
- `raw_headers` is NOT in response
- Nonexistent message_id fails
- Cross-account access blocked (IDOR)
- Message with no attachments returns empty array

---

## Phase 3: `get_thread`

**Action:** `get_thread`
**Input:** `thread_id` (required)
**Output:** Thread metadata + all messages ordered by date ASC.

**Query thread:**
```sql
SELECT id, subject, message_count, preview, is_read, is_starred, last_message_at
FROM threads WHERE id = ? AND account_id = ?
```

Note: whitelist columns (don't return `thread_key` or `account_id`).

**Query messages:**
```sql
SELECT id, uid, folder_name, message_id, subject, from_address, from_name,
       to_addresses, cc_addresses, date, is_read, is_starred,
       has_attachments, text_body, in_reply_to, refs
FROM messages WHERE thread_id = ? AND account_id = ?
ORDER BY date ASC, id ASC
```

Note: `AND account_id = ?` for defense-in-depth (finding #5). Includes `cc_addresses`, `in_reply_to`, `refs` (finding #19). Excludes `html_body` (thread view uses text_body; html available via `get_message`).

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "thread": {
      "id": 5, "subject": "Hello", "message_count": 3,
      "preview": "Latest message preview...", "is_read": 0,
      "is_starred": 1, "last_message_at": "2026-08-26T10:00:00Z"
    },
    "messages": [
      { "id": 1, "date": "2026-08-25T08:00:00Z", "from_address": "a@b.com", ... },
      { "id": 2, "date": "2026-08-25T14:00:00Z", "from_address": "x@y.com", ... },
      { "id": 3, "date": "2026-08-26T10:00:00Z", "from_address": "a@b.com", ... }
    ]
  }
}
```

**Tests:**
- Returns thread with all messages ordered by date ASC
- Thread metadata fields are correct
- Messages include cc_addresses, in_reply_to, refs
- Nonexistent thread_id fails
- Cross-account access blocked
- Thread with single message works

---

## Phase 4: `set_flags`

**Action:** `set_flags`
**Input:** `message_id` (required), `is_read` (optional bool), `is_starred` (optional bool), `folder_name` (optional — move)
**Output:** Updated message state.

### Flag-only changes (read/starred)

Local SQLite update only. Sync reconciles flags via `storeMessage` (`index.php:1716-1720`).

```sql
UPDATE messages SET is_read = ?, is_starred = ?
WHERE id = ? AND account_id = ?
```

Only bind columns actually provided in input. Build SET clause from a **whitelist** (`is_read`, `is_starred`, `folder_name`); never interpolate input keys.

### Move / delete

Requires IMAP connection (same pattern as `actionCompose` connects to SMTP).

1. Load account, decrypt password, connect + login IMAP.
2. Resolve destination folder by role:
   - `delete` → `SELECT name FROM folders WHERE account_id = ? AND role = 'trash'`
   - `archive` → `SELECT name FROM folders WHERE account_id = ? AND role = 'archive'`
   - move to `folder_name` → validate it exists: `SELECT name FROM folders WHERE account_id = ? AND name = ?`
   - If target folder not found → `fail('Trash folder not found. Run sync first.')`
3. IMAP `COPY uid dest_folder` (line reference: `ImapClient` has no `copyMessage` yet — add `copyMessage(int $uid, string $destFolder): ?int` returning new UID).
4. IMAP `STORE uid +FLAGS (\Deleted)` on source.
5. IMAP `EXPUNGE` on source.
6. Locally: `DELETE FROM messages WHERE id = ? AND account_id = ?` (remove source row).
7. If COPY returned a new UID, insert a new message row in the destination folder with the new UID (reuse `storeMessage` logic or a lightweight insert). If COPY didn't return a UID, the next sync will pick it up.
8. `updateThreadAggregates($threadId)`.

**New ImapClient method needed:**
```php
public function copyMessage(int $uid, string $destFolder): ?int
// UID COPY <uid> <destFolder>
// Parse response for new UID: [COPYUID <uidvalidity> <olduid> <newuid>]
```

**SQL (flag-only path):**
```sql
UPDATE messages SET is_read = ? WHERE id = ? AND account_id = ?
```

**Response shape:**
```json
{ "ok": true, "data": { "message_id": 1, "is_read": 1, "is_starred": 0, "folder_name": "INBOX" } }
```

Only echo fields that were actually updated.

**Tests:**
- Marks message as read (local only)
- Toggles starred (local only)
- Partial update: only is_read, is_starred unchanged
- Moves message to Trash via IMAP (env-var gated, skips if no creds)
- Moves message to Archive via IMAP (env-var gated)
- Moves to arbitrary folder via IMAP (env-var gated)
- Delete when Trash role missing → fails with clear error
- Nonexistent message fails
- Cross-account blocked
- Thread aggregates updated after flag change
- Move to same folder is a no-op (or fails gracefully)

---

## Phase 5: `batch_action`

**Action:** `batch_action`
**Input:** `message_ids` (required, array), `action` (required: `read`, `unread`, `star`, `unstar`, `delete`, `archive`, `move`), `folder_name` (required if action=move)
**Output:** Count of affected messages.

### Validation
1. `message_ids` must be non-empty array.
2. Cast every ID to `(int)`, reject `<= 0`.
3. Dedupe (`array_unique`).
4. Cap at 200 IDs. `fail()` if exceeded.
5. Validate `action` against whitelist.

### Flag-only batch (read/unread/star/unstar)

```sql
-- read
UPDATE messages SET is_read = 1 WHERE id IN (?, ?, ...) AND account_id = ?
-- unread
UPDATE messages SET is_read = 0 WHERE id IN (?, ?, ...) AND account_id = ?
-- star
UPDATE messages SET is_starred = 1 WHERE id IN (?, ?, ...) AND account_id = ?
-- unstar
UPDATE messages SET is_starred = 0 WHERE id IN (?, ?, ...) AND account_id = ?
```

Only messages matching the IN clause AND account are affected. Count `affected = $pdo->rowCount()`.

### Move/delete batch

Same IMAP flow as Phase 4, per message. Collect affected thread IDs before deletion, call `updateThreadAggregates()` for each unique thread after batch completes.

**Response shape:**
```json
{ "ok": true, "data": { "affected": 5 } }
```

**Tests:**
- Batch read marks all as read
- Batch unread marks all as unread
- Batch star/unstar works
- Batch delete moves to Trash (env-var gated)
- Batch move to custom folder (env-var gated)
- Empty message_ids fails
- Invalid action fails
- IDs capped at 200
- Duplicate IDs deduped
- Non-numeric/negative IDs rejected
- Mixed valid/invalid IDs: only valid ones affected
- Thread aggregates updated after batch

---

## Phase 6: `search`

**Action:** `search`
**Input:** `query` (required), `folder` (optional filter), `limit` (optional, default 50, max 100)
**Output:** Matching messages ranked by relevance. No cursor pagination (FTS5 `rank` is not keyset-paginatable).

### FTS5 Migration

Separate from `EMMMAIL_SCHEMA` (statement splitter can't handle trigger bodies with `;`). Use versioned migration in `initDatabase()`:

```php
function migrateDatabase(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 2) {
        $pdo->exec(<<<'SQL'
            CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
                subject, from_address, from_name, text_body,
                content='messages', content_rowid='id'
            );

            CREATE TRIGGER IF NOT EXISTS messages_ai AFTER INSERT ON messages BEGIN
                INSERT INTO messages_fts(rowid, subject, from_address, from_name, text_body)
                VALUES (new.id, new.subject, new.from_address, new.from_name, new.text_body);
            END;

            CREATE TRIGGER IF NOT EXISTS messages_ad AFTER DELETE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, subject, from_address, from_name, text_body)
                VALUES ('delete', old.id, old.subject, old.from_address, old.from_name, old.text_body);
            END;

            CREATE TRIGGER IF NOT EXISTS messages_au AFTER UPDATE ON messages BEGIN
                INSERT INTO messages_fts(messages_fts, rowid, subject, from_address, from_name, text_body)
                VALUES ('delete', old.id, old.subject, old.from_address, old.from_name, old.text_body);
                INSERT INTO messages_fts(rowid, subject, from_address, from_name, text_body)
                VALUES (new.id, new.subject, new.from_address, new.from_name, new.text_body);
            END;

            INSERT INTO messages_fts(messages_fts) VALUES('rebuild');
            SQL
        );
        $pdo->exec('PRAGMA user_version = 2');
    }
}
```

Notes:
- `html_body` excluded from FTS (HTML markup pollutes ranking).
- `rebuild` populates the index for pre-existing rows.
- Triggers keep FTS in sync for future INSERT/UPDATE/DELETE.
- Check FTS5 availability: wrap in try/catch; if FTS5 not compiled, `search` returns `fail('FTS5 not available', 501)`.

### Query escaping

FTS5 MATCH has its own syntax. Raw user input with `"`, `*`, `AND`, `OR`, `NOT`, `NEAR` causes malformed expressions → uncaught PDOException. Fix: wrap each token in double quotes for literal matching.

```php
function escapeFtsQuery(string $query): string
{
    $query = trim($query);
    if ($query === '') return '';
    // Split on whitespace, wrap each token in double quotes for literal match
    $tokens = preg_split('/\s+/', $query);
    $escaped = array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"', $tokens);
    return implode(' ', $escaped);
}
```

**Query:**
```sql
SELECT m.id, m.uid, m.folder_name, m.subject, m.from_address, m.from_name,
       m.to_addresses, m.date, m.is_read, m.is_starred, m.has_attachments, m.thread_id,
       SUBSTR(m.text_body, 1, 200) AS preview
FROM messages_fts fts
JOIN messages m ON m.id = fts.rowid
WHERE messages_fts MATCH ? AND m.account_id = ?
  AND (? IS NULL OR m.folder_name = ?)
ORDER BY rank
LIMIT ?
```

**`limit` validation:** Clamp to `[1, 100]`.

**Response shape:** Same as `list_messages` (minus `has_more`/`next_cursor`).

```json
{
  "ok": true,
  "data": {
    "messages": [...]
  }
}
```

**Tests:**
- Finds messages by subject keyword
- Finds by sender name
- Finds by body text
- Folder filter works
- Empty query returns empty
- Special chars in query don't cause 500 (quotes, AND, OR, *)
- FTS5 triggers keep index in sync after insert/update/delete
- Limit clamped to 100
- FTS5 not available → 501 (if compiled without FTS5)

---

## Implementation Order

| Phase | Endpoint | LOC est. | Dependencies |
|-------|----------|----------|--------------|
| 0 | `list_folders` | ~40 | none |
| 1 | `list_messages` | ~70 | none |
| 2 | `get_message` | ~50 | none |
| 3 | `get_thread` | ~55 | none |
| 4 | `set_flags` | ~120 | `ImapClient::copyMessage` (new), `updateThreadAggregates` (exists), folder role resolution |
| 5 | `batch_action` | ~90 | phase 4 logic |
| 6 | `search` | ~110 | FTS5 migration, `escapeFtsQuery` helper |

Total: ~535 LOC implementation + ~400 LOC tests.

## New Infrastructure (added across phases)

| Item | Phase | Description |
|------|-------|-------------|
| `ImapClient::copyMessage()` | 4 | `UID COPY <uid> <dest>`, parse COPYUID response |
| `resolveFolderByRole()` | 4 | `SELECT name FROM folders WHERE account_id = ? AND role = ?` |
| `migrateDatabase()` | 6 | Versioned migration for FTS5 (user_version 1 → 2) |
| `escapeFtsQuery()` | 6 | Wrap user tokens in double quotes for FTS5 MATCH |
| Composite cursor index | 1 | `CREATE INDEX IF NOT EXISTS idx_messages_account_folder_date_id ON messages(account_id, folder_name, date DESC, id DESC)` |

## Verification

After each phase:
1. Run `php tests.php` — all new tests pass, 0 regressions.
2. Run full suite — confirm total count increases.
3. Update README.md with new endpoint docs.
4. Update docs/memory.md.
