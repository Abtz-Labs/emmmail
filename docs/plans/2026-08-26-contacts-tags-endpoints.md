# Contacts & Tags API Endpoints Plan

**Date:** 2026-08-26
**Status:** done

## Overview

Implement 4 endpoints: `list_contacts`, `search_contacts`, `list_tags`, `create_tag`. Contacts are auto-populated from message addresses during sync. Tags are user-created labels. Message tagging (`tag_message`/`untag_message`) is deferred to a future phase — the `message_tags` table is created now but no endpoints use it yet.

---

## Schema (new tables)

Add to `EMMMAIL_SCHEMA`:

```sql
CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    email TEXT NOT NULL,
    name TEXT,
    use_count INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, email)
);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL COLLATE NOCASE,
    color TEXT NOT NULL DEFAULT '#6366f1',
    UNIQUE(account_id, name)
);

CREATE TABLE IF NOT EXISTS message_tags (
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (message_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_message_tags_tag ON message_tags(tag_id);
```

Notes:
- `name COLLATE NOCASE` on tags makes uniqueness case-insensitive (finding #2).
- No redundant single-column indexes — `UNIQUE(account_id, email)` and `UNIQUE(account_id, name)` already cover prefix queries (finding #12).
- `message_tags` has no `account_id` — ownership verified in application layer by checking both `messages.account_id` and `tags.account_id` when tag/untag endpoints are added (finding #9).

---

## Phase 1: Schema + `updateContacts()` hook

Add the 3 tables to `EMMMAIL_SCHEMA`.

### `updateContacts()` helper

```php
function updateContacts(PDO $pdo, int $accountId, array $addresses): void
{
    $st = $pdo->prepare('INSERT INTO contacts (account_id, email, name) VALUES (?, ?, ?)
        ON CONFLICT(account_id, email) DO UPDATE SET
        use_count = use_count + 1,
        last_used_at = datetime("now"),
        name = CASE WHEN excluded.name != \'\' THEN excluded.name ELSE contacts.name END');
    $seen = [];
    foreach ($addresses as $a) {
        if (!is_array($a)) continue;                                          // finding #10
        $email = strtolower(trim((string) ($a['email'] ?? '')));               // finding #11: trim/lowercase first
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($seen[$email])) continue;                                   // finding #18: dedupe within message
        $seen[$email] = true;
        $name = sanitizeContactName((string) ($a['name'] ?? ''));             // finding #7
        $st->execute([$accountId, $email, $name]);
    }
}
```

### `sanitizeContactName()` helper (finding #7)

```php
function sanitizeContactName(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);   // strip control chars
    $name = preg_replace('/\s+/', ' ', $name);               // collapse whitespace
    $name = trim($name);
    if (mb_strlen($name) > 200) $name = mb_substr($name, 0, 200);
    return $name;
}
```

### Hook into `storeMessage()`

After the message INSERT (after line 1769 in current `index.php`), before the early return for existing messages:

```php
$contactAddresses = [];
if ($fromAddress !== '') $contactAddresses[] = ['email' => $fromAddress, 'name' => $fromName];
foreach (json_decode($toAddresses, true) ?? [] as $a) $contactAddresses[] = $a;
foreach (json_decode($ccAddresses, true) ?? [] as $a) $contactAddresses[] = $a;
updateContacts($pdo, $accountId, $contactAddresses);
```

Note: hook runs only for newly-inserted messages (not re-synced existing ones). This is intentional — re-syncs don't bump `use_count`.

### Router changes

Add to `handleRequest()` switch:
```php
case 'list_contacts': actionListContacts(); break;
case 'search_contacts': actionSearchContacts(); break;
case 'list_tags': actionListTags(); break;
case 'create_tag': actionCreateTag(); break;
```

All 4 are authenticated (not in `$publicActions`).

**Tests:**
- Schema includes contacts, tags, message_tags tables
- `updateContacts` inserts new contact
- `updateContacts` upserts existing contact (increments use_count)
- `updateContacts` preserves existing name when new name is empty (finding #1)
- `updateContacts` rejects invalid emails
- `updateContacts` rejects non-array entries (finding #10)
- `updateContacts` dedupes same email within one message (finding #18)
- `sanitizeContactName` strips control chars
- `sanitizeContactName` caps at 200 chars

---

## Phase 2: `list_contacts`

**Action:** `list_contacts`
**Input:** `q` (optional search query), `limit` (optional, default 300, max 500)
**Output:** Contacts sorted by use_count DESC (most-used first).

### Shared `queryContacts()` helper (finding #4)

```php
function queryContacts(PDO $pdo, int $accountId, string $q, int $limit): array
{
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\%_') . '%';          // finding #16: escape LIKE wildcards
        return fetchAll($pdo, 'SELECT email, name, use_count FROM contacts WHERE account_id = ? AND (LOWER(email) LIKE LOWER(?) OR name LIKE ? COLLATE NOCASE) ORDER BY use_count DESC, email LIMIT ?', [$accountId, $like, $like, $limit]);
    }
    return fetchAll($pdo, 'SELECT email, name, use_count FROM contacts WHERE account_id = ? ORDER BY use_count DESC, email LIMIT ?', [$accountId, $limit]);
}
```

Notes:
- `LOWER(email) LIKE LOWER(?)` for case-insensitive email search (finding #3).
- `name LIKE ? COLLATE NOCASE` for case-insensitive name search (finding #3).
- LIKE wildcards escaped with `addcslashes` (finding #16).
- Shared by both `list_contacts` and `search_contacts` (finding #4).

### `actionListContacts()`

```php
function actionListContacts(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $q = trim((string) ($input['q'] ?? ''));
    $limit = max(1, min(500, (int) ($input['limit'] ?? 300)));   // finding #8: clamp
    $pdo = db();
    $contacts = queryContacts($pdo, $accountId, $q, $limit);
    foreach ($contacts as &$c) {
        $c['use_count'] = (int) $c['use_count'];                  // finding #13: cast to int
    }
    unset($c);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}
```

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "contacts": [
      { "email": "alice@example.com", "name": "Alice", "use_count": 15 }
    ]
  }
}
```

**Tests:**
- Returns contacts sorted by use_count
- Search by email works
- Search by name works
- Search is case-insensitive (mixed-case query matches lowercase stored email)
- Limit respected
- Limit clamped: `limit=0` → 1, `limit=999` → 500
- LIKE wildcards in query don't return unexpected results
- Empty result for no contacts
- Rejects invalid session

---

## Phase 3: `search_contacts`

**Action:** `search_contacts`
**Input:** `q` (required search query), `limit` (optional, default 10, max 20)
**Output:** Top matching contacts for autocomplete.

Uses `queryContacts()` helper with prefix match for better autocomplete relevance (finding #15):

```php
function actionSearchContacts(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $q = trim((string) ($input['q'] ?? ''));
    if ($q === '') fail('Missing required field: q');
    $limit = max(1, min(20, (int) ($input['limit'] ?? 10)));
    $pdo = db();
    // Prefix match for autocomplete (finding #15)
    $like = addcslashes($q, '\%_') . '%';
    $contacts = fetchAll($pdo, 'SELECT email, name FROM contacts WHERE account_id = ? AND (LOWER(email) LIKE LOWER(?) OR name LIKE ? COLLATE NOCASE) ORDER BY use_count DESC, email LIMIT ?', [$accountId, $like, $like, $limit]);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}
```

Note: `search_contacts` uses prefix match (`q%`) for better autocomplete relevance. `list_contacts` uses substring match (`%q%`) for broader search.

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "contacts": [
      { "email": "alice@example.com", "name": "Alice" }
    ]
  }
}
```

Note: omits `use_count` from response (autocomplete doesn't need it).

**Tests:**
- Returns matching contacts
- Prefix match: "ali" matches "alice@example.com" but not "bob@alice.com"
- Limit defaults to 10
- Limit clamped: `limit=0` → 1, `limit=50` → 20
- Empty query fails
- Empty result for no matches
- Rejects invalid session

---

## Phase 4: `list_tags`

**Action:** `list_tags`
**Input:** none (account from session)
**Output:** All tags for the account.

**Query:**
```sql
SELECT id, name, color FROM tags WHERE account_id = ? ORDER BY name COLLATE NOCASE
```

**Response shape:**
```json
{
  "ok": true,
  "data": {
    "tags": [
      { "id": 1, "name": "Important", "color": "#6366f1" }
    ]
  }
}
```

**Tests:**
- Returns tags sorted by name (case-insensitive)
- Returns empty array when no tags
- Includes id, name, color
- `id` is int, not string
- Rejects invalid session

---

## Phase 5: `create_tag`

**Action:** `create_tag`
**Input:** `name` (required), `color` (optional, default `#6366f1`)
**Output:** Created tag ID, name, color.

**Logic:**
1. Validate name is non-empty, trim, cap at 100 chars.
2. Validate color format: `#[0-9a-fA-F]{3,4,6,8}` (finding #5), else default.
3. INSERT into tags. If UNIQUE constraint fails → `fail('A tag with this name already exists', 409)`.

**SQL:**
```sql
INSERT INTO tags (account_id, name, color) VALUES (?, ?, ?)
```

**Error handling:** Catch `PDOException`, check error code for UNIQUE constraint violation (finding #17). SQLite UNIQUE violation = error code `23000` or `HY000` with message containing "UNIQUE".

**Response shape:**
```json
{ "ok": true, "data": { "id": 1, "name": "Important", "color": "#6366f1" } }
```

**Tests:**
- Creates tag with name
- Creates tag with custom color
- Defaults color to #6366f1
- Rejects empty name
- Rejects duplicate name (409)
- Same name different case rejected (case-insensitive uniqueness)
- Same name different account succeeds
- Rejects invalid color format (uses default)
- Rejects invalid session

---

## Deferred (future phase)

- `tag_message` / `untag_message` — attach/detach tags from messages
- `batch_tag` — bulk tag/untag
- Tag filtering in `list_messages` / `get_thread`
- Tag display in message/thread responses
- When added: verify both `messages.account_id` and `tags.account_id` match session (finding #9)

---

## Implementation Order

| Phase | What | LOC est. | Dependencies |
|-------|------|----------|--------------|
| 1 | Schema + `updateContacts()` + `sanitizeContactName()` + hook + router | ~50 | none |
| 2 | `list_contacts` + `queryContacts()` helper | ~25 | Phase 1 |
| 3 | `search_contacts` | ~20 | Phase 1 |
| 4 | `list_tags` | ~15 | schema |
| 5 | `create_tag` | ~30 | schema |

Total: ~140 LOC implementation + ~150 LOC tests.

## Verification

After each phase:
1. Run `php tests.php` — all new tests pass, 0 regressions.
2. Run full suite — confirm total count increases.
3. Update README.md with new endpoint docs.
4. Update docs/memory.md.
