# Empty folder sidebar on secondary pages

**status:** done (code + tests). Manual browser pass not run.

## The report

Opening Settings sometimes made every folder in the sidebar disappear. `g i` then did
nothing but toast `No such folder.`

## Cause

`cached_folder_list()` (index.php:2505) returns `[]` on any cache miss and never connects to
IMAP. That is by design — the sidebar is chrome, and a login handshake per secondary page is
not worth it. But the pages that read through it have no second source:

| Reader | Line | Consequence of `[]` |
|---|---|---|
| `render_settings()` sidebar | 5900 | Empty `<ul>` under the word "Folders" |
| `render_compose()` sidebar | 5894 | Same |
| `render_read()` Move picker | 5888 | The Move affordance disappears |
| `render_page()` `data-kb-map` | 5745 | `kb_folder_map()` returns `[]`, so 5759 omits the attribute and every `g X` toasts |
| `handle_settings()` `data-folders` | 5635 | The Manage dialog lists no folders |

Three ways in, all reproduced:

1. **TTL.** `FOLDER_LIST_TTL_SECONDS` is 900. Open Settings more than 15 minutes after the
   last inbox visit and the row is stale, so the read misses. This is the everyday "sometimes".
2. **Clear local cache.** `db_clear_cache_for_user()` deletes `folder_list_cache` (2129), then
   `handle_settings()` redirects to `?page=settings`, which renders the empty sidebar.
3. **A name `json_encode()` cannot encode.** `db_set_folder_list()` binds `json_encode($folders)`
   unchecked (2325). On a non-UTF-8 folder name that is `false`, PDO stores `''`, and
   `json_decode('')` is `null` on every read — a permanent miss that no refresh can clear.

Nothing detected the empty state, so it rendered as a bare heading, an empty list and a
**New folder** button.

## The fix

**Stale beats empty. Serve the last known good list, and never store a broken one.**

The TTL answers "may the folder list have changed?" — it belongs to the inbox, which already
pays for a connection and re-LISTs. It does not answer "is there anything to render?", and
using it for that is what blanked the sidebar. So the TTL splits in two: `folder_list_for()`
keeps it, `cached_folder_list()` serves any stored list whatever its age.

| Step | Change | Case |
|---|---|---|
| 1 | `cached_folder_list()` reads past the TTL | TTL |
| 2 | `db_set_folder_list()` refuses an unencodable payload, keeping the previous row | bad name |
| 3 | `kb_folder_map()` always resolves `i` => `INBOX` | Clear local cache |
| 4 | `view_sidebar()` says so when the list is empty | all |

### 1. `cached_folder_list()` reads past the TTL

`db_get_folder_list()` already takes the TTL per call, so the chrome path passes an
unbounded one. `folder_list_for()` is unchanged: the inbox still re-LISTs every 900 s.

Accepts a stale sidebar in exchange for never a blank one. A folder created from another
client appears on the next inbox visit, which is where the connection already is.

### 2. `db_set_folder_list()` refuses an unencodable payload

Encode first, then bind. On `false`, return `false` and leave the row alone.

The alternative — `JSON_INVALID_UTF8_SUBSTITUTE` — was rejected: a substituted name is a name
that cannot be SELECTed, so it would trade a loud cache miss for a quiet wrong folder.

### 3. `kb_folder_map()` always resolves `i` => `INBOX`

RFC 3501 §5.1 requires a folder named `INBOX`, and this codebase already treats it as
unconditional: `syncTitle()` reads the `INBOX` badge, and the poll chain names `INBOX` on
every page with no chip. `g i` is the one jump that can never be dead.

Every other role still requires a match, so `No such folder.` stays honest.

Known gap, unfixed: right after **Clear local cache** the sidebar is still empty until an
inbox visit. `g i` works; the other five do not. Closing it needs the connection the stale
fix exists to avoid.

Second gap, unfixed: a server that sends a non-UTF-8 folder name makes every
`db_set_folder_list()` write fail. The listings then re-LIST on each visit and the sidebar
stays empty with the note. Loud, and no longer permanent — **Clear local cache** clears the
refused state.

### 4. `view_sidebar()` says so

When `$folders` is empty, the `<ul>` is replaced by a `.sidebar-empty` note. The new-folder
button stays: creating a folder is the way out, and it does not need the list.

## Tests

`tests/unit.php`, 8 new assertions:
- `cached_folder_list()` serves a stale row; `folder_list_for()` on the same row still
  refreshes, so the two paths stay distinguishable.
- `db_set_folder_list()` returns `false` on an unencodable list and the good row survives.
- `kb_folder_map([], null)` has `i` and nothing else; a stale inbox mapping loses to it.
- `view_sidebar([], ...)` has the note and no `<ul>`; a populated sidebar has no note.

`just test` 2528 passed, 0 failed, 2 smoke skips (no credentials).

## Docs

- `docs/knowledge/imap.md` — the two-reader split, and "never cache an empty **or broken**
  LIST" with why `JSON_INVALID_UTF8_SUBSTITUTE` is not the fix.
- `docs/knowledge/frontend.md` — the Inbox fallback in the Keyboard section.
- `README.md` — what `folder_list_cache` serves, and to whom.
