# Threaded conversations

**Status:** done
**Date:** 2026-10-05
**Verified:** all 11 steps implemented, `just test` green (2997 passed, 2 smoke skips). The
manual browser pass is **NOT run** — the conversation reader's keyboard scoping, the
open/close iframe re-measure, the count chip in both themes at phone width, a live upgrade of
a pre-v4 cache, and a real Sent reply joining an Inbox thread all still need eyes.
**Reviewed:** 2026-10-05 — five subagent reviews (IMAP, PHP/SQLite/security, frontend,
testing, process). The plan below is the post-review revision. The five blockers the
reviews found are marked **[BR]**; the review that claimed `References` is not in the
batched FETCH is **wrong** (two probes and a local `ext-imap` run show it is in the same
command) — see "The wire fact".

## Problem

Emmmail lists every message as its own row. A reply is a second row with the same
subject, so an "Auto pooling test" thread reads as three unrelated messages
(`view_mailbox()` at `index.php:9902` renders one `.mail` per cached row). Every other
client groups a reply chain into one conversation.

The cache has the raw material but throws it away. `imap_fetch_overview()` returns
`message_id`, `in_reply_to` and `references`, and it runs inside the sync's existing
batched FETCH — but `overviewRange()` (`index.php:4110`) never reads them and
`db_upsert_messages()` (`index.php:1761`) has no column for them. The data is one line
away.

## Goal

- One row per conversation in a folder listing, with a message-count badge.
- Opening a conversation shows every message, oldest→newest, newest expanded.
- Threads are cross-folder: a reply filed in Sent joins the Inbox conversation.
- No new IMAP round trips on the sync path (the Sent-warm step is a separate, stated cost).
- Drafts and search results stay flat.

## The wire fact

ext-imap's `imap_fetch_overview()` issues one FETCH that already contains
`BODY.PEEK[HEADER.FIELDS (… References)]` alongside ENVELOPE/INTERNALDATE/RFC822.SIZE/
FLAGS, so reading `references` **adds no command**. Two reviewers confirmed this by
running `tests/_imap_stub.php` against the real extension; the process review's contrary
claim is rejected.

The catch, and the reason step 3 changes a stub: `tests/_imap_stub.php:134` answers that
header section with `"stub body for uid N\r\n"`, not a header, so `$msg->references` is
currently never populated. The stub must gain a `references` scenario key and answer the
`HEADER.FIELDS` section with a real `References:` line. `message_id`/`in_reply_to` already
ride in the ENVELOPE the stub emits (`_imap_stub.php:104-107`).

## Decisions (and why not the alternative)

**Thread identity is one global root Message-ID.** Root = first `References` token, else
the `In-Reply-To` token, else the message's own `Message-ID`. Emmmail already writes the
full chain (`smtp_build_raw_message()`, `index.php:1311`; `reply_form_data()`,
`index.php:10281-10283`), and `handle_send()` sends one `$raw` and APPENDs the same bytes
to Sent (`index.php:5139,5182`), so the Sent and Inbox copies carry the same root and join
with no per-folder resolution. A `(user_email, thread_key)` index is enough.

**Store `thread_key` only in v1.** The three raw ids are inputs to the key, not read
queries. The deferred re-key pass and the double-Sent-copy dedupe are the only future
consumers, and a later migration can add the columns. This answers the review's
"cut the speculation" finding.

**v4 rebuilds the message cache.** Pre-existing rows have no ids, and the sync only fetches
`UID > max+1`, so it can never repair them; inserting `thread_key=''` would collapse every
legacy message into one thread. Bodies are never cached, so the messages table is
disposable. `case 4` deletes its rows; the next visit to each folder cold-syncs and threads
correctly. This is a destructive step the user approved.

**Group in SQL.** SQLite's `GROUP BY thread_key` and `COUNT(DISTINCT thread_key)` replace
PHP bucketing. One helper, three queries.

**The conversation reader is a new route.** `view_read()` emits a whole `.card` with its
own action bar, move dialog and `?page=read` hrefs; a thread renders N of those. Extract a
per-message partial, do not nest.

**Thread rows stay thread-aware.** The review found the naive row (`data-uid` = anchor only)
makes read/bulk controls lie: a bold row whose anchor is read posts a no-op, and deleting a
conversation removes one message but hides all N. The user chose the faithful fix:
thread-wide read toggle, and a bulk pick that selects the whole thread.

## Data model — schema v4

`db_migration_step()` (`index.php:1500`) gains `case 4`; `SCHEMA_VERSION` (`index.php:73`)
becomes 4. Each statement is guarded and the case runs once:

```
ALTER TABLE messages ADD COLUMN thread_key TEXT DEFAULT ''   -- guard with db_has_column()
CREATE INDEX IF NOT EXISTS idx_msg_thread
  ON messages(user_email, folder, thread_key, date_ts DESC, uid DESC)
CREATE INDEX IF NOT EXISTS idx_msg_thread_user
  ON messages(user_email, thread_key)
DELETE FROM messages              -- cache is disposable; forces a fresh cold sync
UPDATE folder_state SET cached_rows = 0, last_full_ts = 0
```

`db_upsert_messages()` writes `thread_key` on INSERT (like `has_attachment`) and **also**
in `DO UPDATE SET` **only when `excluded.thread_key <> ''`** — a re-fetched envelope must be
able to repair a sentinel/garbage key, but must never blank a good one. (Review B2's fix,
made non-destructive once the cache is rebuilt.)

## Thread key — a pure, validated function

`extract_msgid(string $header): string` returns the first `<...>` token, or `''`, capped at
400 bytes, rejecting control chars. `thread_root(string $message_id, string $in_reply_to,
string $references): string`:

1. first `extract_msgid($references)` token;
2. else `extract_msgid($in_reply_to)`;
3. else `extract_msgid($message_id)`;
4. else `''`.

The caller replaces `''` with sentinel `'uid:' . $folder . ':' . $uid`. `thread_key_is_safe()`
(`^<[^<>\x00-\x1f]{0,400}>$` or the sentinel form) gates the reader route, mirroring
`imap_folder_name_is_safe()` (`index.php:2628`).

**Documented limitation:** an `In-Reply-To`-only reply to a *middle* message keys on its
parent, not the root, and fragments. All mainstream clients send `References`, so this is
rare. `imap_thread()` (verified present in c-client 2007f) would remove it but is
per-folder and needs server THREAD support, so it cannot serve cross-folder — named and
rejected.

## Sync — extraction on the real path

`overviewRange()` (`index.php:4086-4131`) adds `message_id`, `in_reply_to`, `references` to
each `$row` from the same `$msg`. It is the function `sync_folder()` calls
(`index.php:3031,3043`); adding the ids to `fetchOverviews()` alone — the original plan's
mistake — would have left the cache empty and produced no threads. [BR]

`sync_folder()` computes `thread_key` (it is the layer that knows `$folder`, so the sentinel
has one home here) for every upsert row before `db_upsert_messages()` (`index.php:3074`).
`fetchOverviews()` (`index.php:3483`) gets the same three keys for the live fallback's
parity.

Tests: `fetchOverviews()`/`overviewRange()` parse all three; and an **exact** command-count
assertion on `overviewRange()` for a known range, because today's page/sync guards are loose
upper bounds (`tests/imap.php:231,455`) and would not catch a per-message regression. The
existing exact assertion (`imap.php:156`) is the pattern.

## Listing — one row per thread

**Deviation from the reviewed plan (found during step 5, 2026-10-05):** the reviewed
design paginated conversations over the cache and fell back to the flat live tail on a
deeper page. That double-shows messages whenever threads collapse many messages: with 500
cached messages forming 100 conversations, thread page 6 falls back to live *message* page
6, which replays messages already shown inside thread pages 1-5. Two page schemas cannot
share one `?p=`. The rule below is the fix, and it is simpler.

`folder_view_data()`:

- Keep the **server message** `total`/`cached` for the existing cold/window tests
  (`index.php:2472,2493`). Thread grouping never removes the older-mail path.
- Thread a folder **only when its whole message set is cached**: not Drafts and
  `$total <= $cached`. Then `threadCount = db_count_threads()`, `pages =
  ceil(threadCount/perPage)`, header count = the thread count, `threads = true`. Because
  the whole folder is cached, `$beyondWindow` is necessarily false, so no mixed schema.
- Otherwise (partial window, Drafts, cold, sync failure) the listing is the existing flat
  message path unchanged: same message pagination, same live fallback, `threads = false`.
  Older mail stays reachable because a folder too big to cache is not threaded at all.

`db_list_threads($email, $folder, $page, $perPage)`:
1. The page's keys, ordered by the folder's newest member:
   `SELECT thread_key, MAX(date_ts) newest FROM messages
    WHERE user_email=? AND folder=? GROUP BY thread_key ORDER BY newest DESC, MAX(uid) DESC
    LIMIT/OFFSET`
   (ordering ties break on the folder's own `MAX(uid)`, so no multiplication overflow).
2. All cached members of those keys across the allowed folders, one `IN (...)`; guard the
   empty list.
3. Per thread: `count` (all members), `unread_count`, and the **global-newest member** as
   the display anchor (from/subject/date/attachment, and the row's bold state). The
   in-folder newest supplies `data-uid` and the member uid list. **The plan's original
   in-folder anchor is corrected here**: ranking and display by the global newest is what
   makes a Sent reply surface. [BR-M]

`db_count_threads()` = `COUNT(DISTINCT thread_key)` for the folder.
`db_thread_members($email, $key)` = all members across allowed folders, ascending by
`(date_ts, uid)`.

**Cross-folder member set.** Excludes Drafts, Trash and Junk (a reply-draft carries
`References` and would otherwise inflate counts and be marked read). Uses the existing
`MailImap::isDraftsFolder/isTrashFolder/isJunkFolder` predicates. [BR-M]

`view_mailbox()` renders a thread row when the entry has `thread`:
- `.mail` keeps `data-uid` (in-folder anchor, for the `j/k/x` cursor) and gains
  `data-thread="<h(key)>"`, `data-thread-uids="<csv of in-folder member uids>"`.
- `.mail-main` → `?page=thread&key=<rawurlencode(key)>&folder=<enc>`.
- `.mail-line` gains `.mail-thread-count` (numeral), only when `count > 1`; numeral
  `aria-hidden`, an `aria-label` on the link names "N messages in this conversation"
  (no `title`, which stacks with `.tip::after`).
- `.unread` when any cached member is unread.
- A flat entry renders exactly as today.

## Reader — the conversation

Router case `thread`, `handle_thread()`:

- `key = (string)($_GET['key'] ?? '')` — **read directly, never `urldecode()`** (`$_GET` is
  already decoded; `urldecode()` would turn a legal `+` in a Message-ID into a space).
  Validate with `thread_key_is_safe()`. [BR-M]
- `db_thread_members()`; group by folder; per folder `imap_folder_name_is_safe()` then
  `selectFolder()`; batch the headers ascending in one FETCH; fetch bodies per member
  (bounded — cap at 30 rendered, "N more" after). The original plan's one
  `getEmailByUid()` per member is ~4 commands each and unbounded. [BR-M]
- Mark every unread member read, **per folder**: `selectFolder()` → `markManyAsRead()`
  (one `UID STORE`, and it assumes the mailbox is selected — `index.php:3946`) →
  `cache_mark_read()` per member. Validate each folder first. [BR-M]
- No member resolves → flash "Conversation not found." and return to the folder
  (`handle_read()`'s pattern, `index.php:4809`).

`view_thread(array $members, string $folder, array $opts)`: one `<h1>` (anchor subject); a
stack of `<details class="thread-msg" open|closed>` oldest→newest, newest `open`; each
`<summary>` = avatar + from + date; collapsed body = the message partial. **Exactly one
`#move-dialog`** for the whole thread (the JS uses `doc.querySelector('#move-dialog')`).
Per-message reply/reply-all/forward/delete/spam/move reuse the reader controls.

**Partial extraction (behaviour-preserving).** `view_read()` (`index.php:10522`) is split
into `view_message_body($email,$uid,$folder,$opts)` (avatar/title/meta/attachments/images/
iframe/body) and `view_message_actions(...)` (action bar). `view_read()` calls both, and so
does each thread message. The snapshot test must freeze `$_SESSION['__mail_csrf']` (the
token is `random_bytes`, `index.php:986`) or normalise `value="[0-9a-f]{64}"`; a raw
byte-identical fixture cannot survive. Preserve the iframe sandbox (`index.php:10633`), the
images CTA forms, the reply/replyAll/forward hrefs, and `data-uid/data-folder/data-reply`.
Per-message `data-prev/next` are omitted in thread context.

## Cross-folder: keeping Sent warm

`handle_sync()` (`index.php:5583`) resolves Sent from `cached_folder_list()` (no connection)
and, when it names a folder other than the open one and INBOX, adds
`sync_request($shared, $me, $sent, $opts)`. A missing Sent folder skips silently — it must
not fail the poll. `sync_should_run()`'s throttle applies as to any folder.

**Stated cost:** the first Sent sync is a cold window (up to `SYNC_WINDOW`=500 messages plus
a `full_interval` reconcile of five `uidSet` searches and the attachment backfill). Every
send calls `cache_invalidate_folder()` (`index.php:5184`), so Sent re-syncs on the next poll
— expected, because a just-sent reply must appear in its thread. **Double Sent copies**
(server auto-save plus Emmmail's APPEND can both file the message) are a known pre-existing
quirk, newly visible; out of scope, noted.

## JS and CSS

**Thread-aware row controls (new JS, does not exist today).**
- `.unread` on a thread row means "any member unread". `toggleRead` must post the thread, not
  the anchor: when `data-thread` is present, post `?page=markRead` with `thread=<key>` and
  an explicit direction (the server marks every cached member, across folders). The
  handler gains thread support and keeps per-uid behavior otherwise.
- Bulk pick: `data-thread-uids` (in-folder members) feeds `pickSet`; a pick on a thread row
  adds every in-folder member uid, so `bulkAction`/`bulkRemove` move the set and the row
  hides only when its members are gone. Cross-folder members are intentionally outside bulk
  actions (the existing handlers are single-folder).
- New js scenarios: thread row read toggle posts the thread and unbolds the row; a bulk
  pick selects all in-folder members; deleting them hides the row; a `data-thread-but-single-
  member` row behaves as a normal row.

**Reader active-message contract (new, because N cards break singletons).**
- `readerControl()` is `$('.actions-bar')[0]`, `kbReaderKey()` reads the single `.card`, and
  `#move-dialog` is a single id. Define the *active* message as the last-opened
  `<details.thread-msg>` (default: the newest, which starts open). Scope `readerControl`,
  `kbReaderKey`, and the r/a/f keyboard dispatch to it. The single-message reader keeps one
  card, so its behaviour is unchanged.
- `fitSrcdoc` sizes hidden iframes; a collapsed `<details>` does not re-fit, so re-fit on
  `toggle`. One js scenario per contract.
- Native `<details>` needs no expand JS, and `kbSpaceSelect` already exempts `<summary>`
  (`index.php:8965`).

**Count chip CSS.** `.mail-line` is a `space-between` flex row, not the `.mail` grid; give
the chip a real slot so `.mail-from` is not squeezed at the phone breakpoint. If the chip
reuses `--surface-3`/`--text-faint`, no new token is needed; if a token is added, all three
places (`:root`, `[data-theme="dark"]`, the `prefers-color-scheme` clone) must carry it.

## Tests (red first, per step)

`tests/unit.php`
- `thread_root()`/`extract_msgid()`: references wins, in-reply-to fallback, own-id fallback,
  empty, multi-token/whitespace, cap and control-char rejection.
- v4 migration: fresh DB at 4; legacy v3 upgrades and its `messages` rows are cleared;
  `SCHEMA_VERSION === 4`.
- `db_upsert_messages()` stores `thread_key`; conflict repair when the new key is non-empty,
  never blanking a good key.
- Two no-id messages get distinct `uid:folder:uid` keys (sentinel).
- `db_list_threads()`: original + two replies collapse to one; global-newest anchor;
  thread-ordered pagination (page 2 slices threads); empty-key guard.
- `db_thread_members()`: cross-folder ascending; Drafts/Trash/Junk excluded.
- `db_count_threads()`.
- `view_mailbox()`: one grouped row, count chip, `?page=thread` link, `.unread` on a
  partially-read thread, and a flat row unchanged.
- Boundary: page past cached threads falls back with `data-threads="0"`.
- `view_read()` partial extraction preserves the pinned substrings (existing asserts
  `unit.php:1825,1868,2116`), with a frozen CSRF token.
- `view_thread()`: oldest-first, newest `open`, exactly one `#move-dialog`, one action bar
  per message.
- README/limitation guards in the existing source-guard style.
- Source guards: `case 'thread':`, `function handle_thread(): void`, `handle_sync()` syncs
  Sent, `rawurlencode` of `thread_key`, `SCHEMA_VERSION` bump.

`tests/imap.php`
- Stub gains `references`; `overviewRange()`/`fetchOverviews()` parse the three ids.
- Exact one-command assertion for a known `overviewRange()`.
- `markManyAsRead()` requires a selected mailbox (select-per-folder guard).

`tests/js.php`
- The thread-row read/bulk scenarios above and the reader active-message scenarios.

## Limitations (README)

1. A folder is threaded only when its whole message set is cached (`serverTotal <= cached`,
   i.e. up to `SYNC_WINDOW`=500 messages); a larger folder is listed flat, exactly as before.
2. Cross-folder members appear only once their folder has synced; Sent is kept warm by the
   poll. Drafts/Trash/Junk never join a conversation.
3. Search results and Drafts stay flat.
4. `References`-less replies to a middle message can fragment.
5. A thread renders at most 30 messages; "N more" points at the folder.
6. v4 rebuilds the message cache once: the first visit to each folder cold-syncs.
7. A message auto-saved to Sent by the server *and* filed by Emmmail can appear twice.

## Steps (each starts with its red test)

| # | Step | Status |
|---|---|---|
| 1 | Red: v4 migration tests. Code: `SCHEMA_VERSION`=4, `case 4` (column+indexes+purge) | done |
| 2 | Red: `thread_root`/`extract_msgid`/`thread_key_is_safe` cases. Code: the pure helpers | done |
| 3 | Red: stub `references` + ids-parse + exact command count. Code: `overviewRange()`/`fetchOverviews()` keys; `sync_folder()` computes `thread_key`; INSERT write | done |
| 4 | Red: upsert/conflict, sentinel, `db_list_threads`/`db_thread_members`/`db_count_threads`. Code: the DB layer | done |
| 5 | Red: thread-row + fully-cached/boundary tests. Code: `folder_view_data()` thread gate (`total <= cached`, not Drafts), `view_mailbox()` row + chip + `data-threads` | done |
| 6 | Red: `view_read()` preservation asserts (frozen CSRF). Code: extract `view_message_body`/`view_message_actions` | pending |
| 7 | Red: `view_thread()` tests. Code: `case 'thread'`, `handle_thread()`, `view_thread()`, per-folder select/mark-read, 30-cap | pending |
| 8 | Red: source guard that `handle_sync()` syncs Sent; absence test. Code: the Sent-warm sync | pending |
| 9 | Red: thread-aware read/bulk + reader active-message js scenarios. Code: the JS contracts | pending |
| 10 | Red: CSS token/count-chip guards. Code: chip slot + dark tokens | pending |
| 11 | Docs: README route/DB/limitations, `knowledge/composing.md` (threading), `knowledge/imap.md` (References-in-overview + stub), `knowledge/frontend.md` (active-message), `memory.md` | done |

Run with `php tests/unit.php` / `php tests/imap.php` / `php tests/js.php`; `just test` runs
all four and ignores args. No new suite file (it would be silently skipped).

## Verification

- `just test` green (2 smoke skips without creds).
- Manual browser pass: an Inbox thread with a Sent reply shows one row with the count and the
  Sent sender/date; opening lists oldest→newest, newest expanded; the row leaves `unread`
  after open; read toggle and bulk pick act on the whole thread; delete from the list and
  from the reader behave; both themes; phone width keeps `.mail-line` on one line.
- Upgrade pass: a pre-v4 cache rebuilds on first folder visit and shows threads.
