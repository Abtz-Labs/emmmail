# Message Header Cache

status: done

## Goal

Make list views render with **zero IMAP round trips**. Cache message headers in
SQLite during sync. Read the cache to paint the UI. Correct the cache in the
background and show a sync indicator.

## Non-goals

- No message bodies in the cache. Bodies stay live from IMAP.
- No FTS5. The earlier design dropped it. `LIKE` over 50k rows is 2.7 ms.
- No JS list renderer. A change triggers a reload, which is cheap once cached.
- No multi-account. No daemon.

---

## 1. Measured baseline

All numbers below are **measured**, not modelled, using a wire-level IMAP stub
(`/tmp/imapstub.php`, see §7) that logs every client command. c-client really
connects to it, so the counts are exact. Folder of 200 messages, `perPage = 20`,
`handle_inbox` path.

### Before any of this work: 52 commands

| Commands | Source |
|---|---|
| 1 `CAPABILITY`, 1 `LOGIN` | connection setup |
| 3 `NOOP` | `imap_reopen` / `imap_check` |
| 2 `SELECT` | double select in `getEmails` |
| 1 `UID SORT` | whole-folder sort |
| **20 `UID FETCH <uid> UID`** | `imap_fetch_overview(..., FT_UID)` resolving one UID per round trip |
| 1 `FETCH 181,182:200 (ENVELOPE ...)` | the actual page envelopes, correctly batched |
| **20 `FETCH n (BODYSTRUCTURE FLAGS)`** | `checkAttachment` per message, for the paperclip |
| 1 `LIST`, 1 `CLOSE`, 1 `LOGOUT` | teardown |

The `FT_UID` cost was invisible until measured. There is **no pipelining** in
c-client: every `imap_*` call is one synchronous round trip. The only batching is
the envelope lookahead (`IMAPLOOKAHEAD = 20`), which is why the page envelopes
cost 1 command but BODYSTRUCTURE costs 20.

At 40 ms RTT, TLS 1.2: ~310 ms connect+login, ~50 blocking round trips before
output ⇒ **~2310 ms perceived**.

### After the quick wins: 28 commands

| Commands | Source |
|---|---|
| 1 `CAPABILITY`, 1 `LOGIN`, 1 `NOOP`, 1 `SELECT`, 1 `SORT` | setup + sort |
| 1 `FETCH` | page envelopes |
| **20 `FETCH n (BODYSTRUCTURE FLAGS)`** | the paperclip |
| 1 `LIST`, 1 `LOGOUT` | teardown |

### After Phase 4: 8 commands

Phase 4's test rig exposed the real cause of the paperclip N+1, and it was
**ordering, not a missing API**.

c-client has a 20-message forward lookahead. When a message's structure is not
cached, it issues a *combined* `FETCH (UID ENVELOPE ... BODYSTRUCTURE INTERNALDATE
RFC822.SIZE FLAGS)` across the lookahead window. But two things defeat it:

- calling `imap_fetch_overview()` first caches the envelope, after which each
  structure fetch goes out alone;
- walking newest-first (descending message numbers) never benefits, because the
  lookahead only prefetches *forwards*.

The list view did both. Measured, for one 20-message page:

| Order | Commands |
|---|---|
| overview, then structures descending (was shipped) | 21 |
| overview, then structures ascending | 21 |
| **structures ascending first, then overview** | **1** |

So `fetchOverviews` now resolves attachment flags in ascending message order
*before* the overview call. **Whole-render cost: 8 commands, ~590 ms.** The
paperclip is kept.

| Stage | Commands | Perceived @40 ms RTT |
|---|---|---|
| Original | 52 | ~2310 ms |
| After quick wins | 28 | ~1390 ms |
| After the ordering fix | 8 | ~590 ms |
| **Warm render from the cache (Phase 5)** | **0** | **~30 ms** |
| First visit to a folder (cold sync, Phase 6) | 15 | once per folder |

This invalidates the earlier claim that "20 of the remaining 28 commands are the
paperclip, and only the cache can fix it". There was no batching API — but there
was a batching *path*, and the code was on the wrong side of it.

### What is left

7 blocking commands: `CAPABILITY`, `LOGIN`, `SELECT`, `SORT`, `FETCH`, `LIST`,
plus a `NOOP`. Every one is structural: authenticate, select, sort, fetch the
page, list folders. Only the cache removes them, and only by not connecting at
all.

There is **no** ext-imap way to batch BODYSTRUCTURE explicitly — every structure
accessor is single-msgno. The win above comes from cooperating with c-client's
lookahead, not from an API.


---

## 2. Done

### Phase 0 — Measurement — **DONE**

Built a wire-level IMAP stub instead of adding instrumentation to `index.php`.
Better: it gives exact command counts with no production code changes, and it
runs offline. Recommend moving it into the repo (§7).

Corrected two errors in the first draft of this plan: the baseline is **52
commands and ~2310 ms**, not 27 and 1.5 s.

### Phase 1 — Quick wins — **DONE**

- **`imap_fetch_overview` no longer passes `FT_UID`** (−20 commands). `getEmails`
  and `searchEmails` now work in message numbers; the overview response carries
  the UID anyway. `checkAttachment` takes the msgno directly, dropping a pointless
  `imap_msgno`.
- **`findAttachments` no longer downloads attachment payloads.** Size comes from
  `$part->bytes`, corrected for base64 (`bytes * 3/4`) so the figure matches the
  downloaded file. Verified: a 1000-octet base64 part now reports 750 bytes and
  issues no `BODY[2]` fetch. Read view is 5 commands regardless of attachment
  size. Previously a 20 MB PDF was transferred to render "20480.0 KB".
- **Removed the double `SELECT`** in `getEmails` (−1 `SELECT`, −2 `NOOP`).
- **`selectFolder` memoizes**, and only on success. The memo is set *after* a
  truthy `imap_reopen` and cleared on failure, so a failed select can never be
  short-circuited into looking like a success.
- **`emptyTrash` now aborts if the select fails.** It deletes by *sequence
  number* (`1:N`), so against the wrong mailbox it would have destroyed the first
  N messages of whatever was selected. This was a live data-loss path.
- **`getFolders` memoizes per request** (guarded so a failure is not cached).
  This also removes the duplicate `LIST` in `handle_empty_trash`.
- **`disconnect()` uses plain `imap_close`.** `CL_EXPUNGE` was expunging whatever
  mailbox happened to be selected on every request teardown, including `\Deleted`
  flags set by other clients. Every delete path expunges explicitly already.
- **`handle_send` connects after validation**, so a rejected compose pays no
  IMAP login. Deliberately still above `smtp_send`: `imap_for_session()` redirects
  to logout on failure, which below the send would log the user out after the
  mail had gone.
- **gzip on HTML responses**, enabled inside `render_page` rather than globally,
  because `handle_download` sets an explicit `Content-Length` that gzip would
  invalidate and never routes through `render_page`. Measured over real HTTP:
  **14,767 → 3,621 bytes** on the wire, byte-identical page.
- Fixed `php smoke_test.php` → `php tests_smoke.php` in `Justfile` and `README`.

### Security fix — **DONE** (found during review, not a performance item)

`sanitize_html` did not recurse into the children of a disallowed element. The
child list was snapshotted before the loop, and the unwrap path inserted promoted
children into the same parent, so they were never visited. A `<font>` wrapper
smuggled `<script>`, `javascript:` hrefs and `target` straight through, contained
only by the iframe `sandbox=""`.

Fix: sanitize the subtree **before** unwrapping. Plus a small `$dropEntirely`
list (`script`, `style`, `head`, `title`, `template`) removed with their whole
subtree, so source no longer leaks into the body as visible text — `<style>`
blocks in HTML email were previously rendering their CSS as text.

Scoped deliberately: `iframe`/`object`/`embed` are still *unwrapped*, not
dropped, because they can hold legitimate fallback text, and the recursion fix
makes unwrapping safe. An over-broad first attempt deleted that text and was
caught by an existing test.

### Migrations — **DONE**

Forward-only, versioned with `PRAGMA user_version`. `SCHEMA_VERSION`,
`db_schema_version()`, `db_has_column()`, `db_migration_step()` with one `case`
per version. Step 1 is a baseline of the pre-versioning schema, so an existing
database advances 0 → 1 with no DDL effect. One transaction per step under
`BEGIN IMMEDIATE`: atomic, concurrency-safe, resumable. No `down` path.

Two latent bugs fixed:

- `db_open` assigned the static **before** migrating, so a failed migration
  returned `null` once and then handed back a half-migrated handle forever.
- `PRAGMA journal_mode=WAL` raced. SQLite does not honor `busy_timeout` for a
  journal-mode switch, so four concurrent first-requests produced 1–3 `null`
  PDOs — a silently database-less app. Now attempted only when the mode is not
  already WAL, with a lock conflict treated as non-fatal. Verified over 8
  four-way races.

Tests: 128 → 152.

---

## 3. The decision this plan now hangs on

Phases 1–4 took the render from **~2310 ms to ~590 ms** with no schema, no
staleness, and no privacy change. Phases 5–7 buy the remaining **~590 ms → ~30 ms**
by not connecting at all.

That is still a real win — half a second on every navigation — but it is a much
weaker case than the plan was originally written against, and it is the part
that costs:

- rendering from possibly-stale state, with a sync indicator to explain it;
- subjects, senders and snippets persisting on disk after logout (§8.2);
- the `search_cache` migration and the body-search regression (§5);
- a sync protocol on a library with no CONDSTORE/QRESYNC.

Phases 2–4 are **already built and tested**, so the sunk cost is spent: the
cache, the reconciler and the driver all work. What remains is only wiring the
render path to read from them.

**Worth reconsidering before Phase 5**, because the honest comparison is now
590 ms → 30 ms, not 1390 ms → 30 ms.


---

## 4. Architecture (unchanged in shape, corrected in detail)

```
Request  ──▶ SQLite (messages, folder_state) ──▶ HTML   [0 IMAP calls]
                                                   │
                                                   ▼ page load
                                          fetch('?page=sync&folder=X')
                                                   │
                                          IMAP: STATUS → diff → upsert
                                                   │
                                        {changed:N, unread:N, ts:...}
                                                   │
                                    changed > 0 ? reload : update indicator
```

### Schema

```sql
CREATE TABLE IF NOT EXISTS messages (
  user_email       TEXT    NOT NULL,
  folder           TEXT    NOT NULL,   -- modified UTF-7, exactly as LIST returns it
  uid              INTEGER NOT NULL,
  subject          TEXT    DEFAULT '',
  from_display     TEXT    DEFAULT '', -- decoded form; view_inbox renders this
  from_address     TEXT    DEFAULT '',
  to_display       TEXT    DEFAULT '', -- needed by the Sent view
  to_address       TEXT    DEFAULT '',
  date_ts          INTEGER NOT NULL,   -- imap udate (INTERNALDATE), matches SORTARRIVAL
  size             INTEGER DEFAULT 0,
  unread           INTEGER DEFAULT 0,
  answered         INTEGER DEFAULT 0,
  flagged          INTEGER DEFAULT 0,
  deleted          INTEGER DEFAULT 0,  -- \Deleted but not yet expunged
  has_attachment   INTEGER DEFAULT 0,
  structure_synced INTEGER DEFAULT 0,
  snippet          TEXT    DEFAULT '', -- real body text, filled on read
  cached_at        INTEGER NOT NULL,
  UNIQUE(user_email, folder, uid)
);
CREATE INDEX IF NOT EXISTS idx_msg_list
  ON messages(user_email, folder, date_ts DESC, uid DESC);

CREATE TABLE IF NOT EXISTS folder_state (
  user_email    TEXT    NOT NULL,
  folder        TEXT    NOT NULL,
  uidvalidity   INTEGER,             -- NULL = unknown, never 0-as-unknown
  uidnext       INTEGER,
  total         INTEGER,             -- from STATUS: the authoritative folder size
  unseen        INTEGER,             -- from STATUS: the sidebar unread count
  cached_rows   INTEGER DEFAULT 0,   -- how many rows we hold: the cache floor
  last_sync_ts  INTEGER DEFAULT 0,
  last_full_ts  INTEGER DEFAULT 0,
  PRIMARY KEY (user_email, folder)
);

CREATE TABLE IF NOT EXISTS folder_list_cache (
  user_email TEXT PRIMARY KEY,
  payload    TEXT NOT NULL,          -- JSON from getFolders()
  cached_at  INTEGER NOT NULL
);
```

Corrections against the first draft, all from review:

- `from_display` / `to_display` added. `view_inbox` renders `$e['from']` and
  `$e['to']`, which cannot be reconstructed from name + address.
- `uid DESC` added to the list index. INTERNALDATE has 1-second resolution and
  bulk delivery produces many ties; without it SQLite adds a temp B-tree.
- The `(user_email, folder, unread)` index is **dropped** from the plan. A
  2-value trailing key is near useless and the unread count is 0.131 ms unaided.
- `deleted` column added. The overview already returns it for free, and
  `\Deleted`-but-unexpunged messages appear in both `SEARCH` and the current
  rendered list.
- `oldest_cached_uid` **removed**. UID order is assignment order; pagination is
  date order. Anything APPENDed or COPYed in (Sent, restore-from-trash, a
  migration) gets a high UID with an old date, so a UID cannot define the
  boundary of the newest-N-by-date window. `cached_rows` replaces it.
- The sidebar unread count reads `folder_state.unseen` from STATUS, **not**
  `COUNT(*) WHERE unread = 1`, which is wrong for a windowed cache.

### Sync algorithm

1. `imap_status(fullPath, SA_MESSAGES | SA_UNSEEN | SA_UIDNEXT | SA_UIDVALIDITY)`
   — 1 command, no SELECT needed on an IMAP4rev1 server.
   **Every field is tri-state.** c-client silently falls back to `EXAMINE` on
   pre-rev1 servers and *strips* `SA_UIDNEXT | SA_UIDVALIDITY` when it does, and
   `imap_status` returns `false` on a `\Noselect` folder or missing rights. Read
   fields with `property_exists()`, never `?? 0` — a missing field read as 0
   compares equal forever and freezes the cache permanently.
2. `uidvalidity` known **and** changed → delete every cached row for the folder,
   then cold sync. Unknown → force a full reconcile, never a purge.
3. Skip the add/delete work only when `uidvalidity`, `uidnext`, `total` **and**
   `unseen` are all known and unchanged. RFC 3501 §2.3.1.1 guarantees UIDNEXT
   advances on any arrival even if it is later expunged, so unchanged UIDNEXT
   plus unchanged MESSAGES does prove no adds and no deletes. `unseen` is a free
   extra signal that forces an early flag reconcile.
   **Never skip the flag reconcile on this basis** — flags move neither counter.
4. New messages: `SELECT`, then `imap_fetch_overview("<maxCachedUid+1>:*")`.
   Guards: skip the call entirely when `maxCachedUid + 1 >= uidnext`; clamp the
   low bound to ≥ 1; discard any returned UID below the low bound. RFC 3501
   §6.4.8 requires a UID range to always include the last message, so an
   overshoot returns one stray newest row. Without the skip, that row is
   re-upserted on every sync and `changed > 0` becomes an infinite reload loop.
5. Reconcile, rate-limited by `last_full_ts`:
   - `imap_search('UNDELETED', SE_UID)` for the live set **and
     `imap_search('DELETED', SE_UID)`** for the pending-delete set — **not
     `'ALL'`**. A bare `ALL` sets no criteria bits, so c-client resolves it
     **locally** and pays ⌈N/1000⌉ `FETCH (UID)` commands. Both of these go
     server-side as one `UID SEARCH` each. The pair is required because
     `MESSAGES` counts `\Deleted` messages, so the consistency invariant is
     `count(UNDELETED) + count(DELETED) === MESSAGES`.
   - `UNSEEN`, `FLAGGED`, `ANSWERED` — 1 command each, server-side, tiny payload.
   - Payload is O(folder size) in UIDs (~7 bytes each), not O(1). The first
     draft claimed size-independence; that was wrong. It is ~40× smaller than an
     envelope fetch of `1:*`, which is the real argument.
   - c-client's criteria parser accepts no `UID`, `NOT`, `OR`, `HEADER`,
     `LARGER` or `SMALLER`, so a range-bounded search is not available.
6. Backfill `has_attachment` for at most K rows with `structure_synced = 0`.
   **Reconcile `hasAttachmentInParts` with `isAttachmentPart` first** — they
   disagree today (one requires `disposition = attachment`, the other does not),
   so a `name=`-only part is downloadable but shows no paperclip. Caching freezes
   that inconsistency per UID forever.
7. Write `folder_state` from the **opening** STATUS, never from `max(uid)+1` of
   what was fetched, or an arrival between STATUS and FETCH is recorded as
   already-seen and lost. RFC 3501 §6.3.10 also warns STATUS on the *selected*
   mailbox may be stale.

Cold start: take the newest window by **msgno tail**, not UID arithmetic:
`imap_fetch_overview("$lo:$hi")` with `$hi = total`, `$lo = max(1, total - 499)`,
no `FT_UID`. Exactly 500 contiguous rows, no gap problem. `uidnext - 500` was
wrong: UIDs are sparse after expunges, so on a churned folder it returns almost
nothing, and with an unknown `uidnext` read as 0 it degenerates to `1:*` — a
full-folder fetch. Cost is ⌈500/21⌉ ≈ **24 commands**, once per folder, not the
"≈3" first claimed.

### Data-loss guards (non-negotiable) — implemented in Phase 3

`imap_search` returns **`false` for "no matches"**, indistinguishable from
failure — empty folder, failed SELECT, dropped connection, unparseable criteria
and a server `NO` all produce `false`. Normalising that to `[]` makes the
reconcile compute "every cached UID was deleted".

1. `uidSet()` returns `?array`. `null` means unknown ⇒ **delete nothing**. *(done)*
2. Accept an empty live set only when STATUS says `messages === 0`. *(done)*
3. Accept a deletion list only when
   `count(undeleted) + count(deleted) === messages`. *(done)*
4. Refuse a delete batch larger than `max(50, 20% of cached rows)`; mark the
   folder for a cold resync instead. *(done)*
5. Abort the whole sync unless `selectFolder()` returned `true`. *(done — but
   see Phase 4: `imap_reopen()` lies, so `selectFolder()` had to be reworked to
   detect a refused SELECT via the error stack.)*

---

## 5. Blockers to clear before Phase 5

- **`selectFolder`'s return value** is now checked where it matters: `emptyTrash`
  (Phase 1), `sync_folder` (Phase 4), `handle_delete` and `handle_mark_read`
  (Phase 7). `handle_read`, `handle_reply`, `handle_forward` and `handle_send`'s
  forward fetch still ignore it — they are read-only, so a failed select yields
  "message not found" rather than data loss, but tightening them would be tidier.
- ~~**`getFolders()` cannot be removed from the list handlers.**~~ *(closed in
  Phase 5.)* `imap_for_session()` is now lazy, passed as a factory;
  `folder_list_cache` serves the sidebar; `isTrashFolder`/`isSentFolder` are
  static.
- ~~**Dropping `search_cache` breaks 6 live call sites.**~~ *(defused in Phase 7.)*
  Nothing writes to or reads from it any more. `db_cache_email`,
  `db_search_cache` and `db_remove_cache_uid` have zero callers;
  `db_prune_cache` (login) and `db_purge_cache_for_email` (logout) still run
  against it as harmless no-ops. Dropping the table is now a one-line migration
  whenever you want it — see §8.1.
- ~~**Repointing search at `messages` kills body search.**~~ *(closed in Phase 7)*
  with an explicit "Search message bodies on the server" link
  (`?page=search&server=1`) shown whenever local results are displayed.
- ~~**A null PDO is completely silent.**~~ *(closed in Phase 5.)* An empty or
  unavailable cache falls back to live IMAP and sets a flash error, so the user
  never gets a silently empty mailbox.
- ~~**`?page=sync&folder=X`** folder-name injection.~~ *(closed in Phase 6)* via
  `imap_folder_name_is_safe()`, which rejects braces, CR/LF and control
  characters before any connection is opened.
- ~~**Session lock.**~~ *(closed in Phase 6.)* `handle_sync` copies the
  credentials out of `$_SESSION`, calls `session_write_close()`, then connects,
  and the staleness gate runs before the factory is invoked. PHP-FPM worker
  occupancy remains a real limit on a small pool.
- ~~**`FT_PEEK` is used nowhere.**~~ *(fixed in Phase 7.)* All three body-fetch
  sites pass `FT_PEEK`, asserted on the wire. Downloading an attachment no longer
  marks a message read.

---

## 6. Remaining phases

### Phase 2 — Schema and DB layer — **DONE**

Migration step 2 creates `messages`, `folder_state`, `folder_list_cache`.
`SCHEMA_VERSION` is now 2. Verified on a copy of the live database: 1 → 2 with
all rows preserved, and forcing the version back to 1 re-applies only step 2,
confirming the per-step transactions are resumable. `EXPLAIN QUERY PLAN` shows
`SEARCH messages USING INDEX idx_msg_list` with no temp B-tree.

Functions added: `db_upsert_messages` (batched in one transaction),
`db_list_messages`, `db_count_messages`, `db_max_cached_uid`,
`db_delete_messages`, `db_set_message_flags`, `db_set_message_snippet`,
`db_pending_structure_uids`, `db_set_has_attachment`, `db_get_folder_state`,
`db_set_folder_state`, `db_get_folder_list`, `db_set_folder_list`.

Decisions made while implementing:

- **`db_upsert_messages` does not touch `has_attachment`, `structure_synced` or
  `snippet` on conflict.** Those are filled by separate slower passes, and
  re-syncing an envelope must not discard that work. Tested explicitly.
- **Every function degrades instead of throwing.** They catch `Throwable` and
  return an empty/zero result, because the cache is disposable and a storage
  error must not take the page down. This closes the "uncaught `PDOException` ⇒
  blank 500" blocker for the new tables.
- **`folder_state` counters are nullable end to end.** `db_get_folder_state`
  preserves `null` rather than casting to `int`, so "the server did not tell us"
  never becomes "0". Tested.
- **Bools are hydrated back to real bools** on read, so the view layer does not
  have to know they are stored as integers.

### Phase 3 — Pure reconciliation core — **DONE**

Split into two pure functions rather than one, because the decision of *whether
to fetch* has to be made from STATUS before any UID sets exist:

- `sync_decide(?array $state, array $status, int $now, int $fullInterval)`
  → `['purge_all', 'scan', 'full', 'reason']`
- `sync_reconcile(array $cached, array $server, array $opts)`
  → `['upsert', 'flag_updates', 'delete_uids', 'refused']`

Both touch neither IMAP nor SQLite, so all 50 tests run offline with no double.

`flag_updates` is returned separately from `upsert` so the driver can tell an
envelope write from a flag-only write. Flag updates are emitted **only when the
value actually differs**, which is what makes an already-correct cache produce a
completely empty plan — the property the sync endpoint needs so it does not
report changes forever and loop the client.

**Correction to the reviewed design.** The review proposed
`count($live) === $status['messages']` as the sanity check on deletions. That is
wrong when the live set comes from `UNDELETED`: `MESSAGES` counts
`\Deleted`-but-unexpunged messages, so the check would refuse deletions on any
folder with a pending delete. The implemented invariant fetches **both** sets:

```
count(UNDELETED) + count(DELETED) == MESSAGES
```

which is exact, costs one extra tiny server-side search, and populates the
`deleted` column for free. A `\Deleted`-but-present UID is now marked, not
dropped — tested.

Guards implemented and tested, in order of application:

1. `undeleted` or `deleted` unknown (`null`) ⇒ `refused = 'unknown-live-set'`,
   delete nothing. This is the `imap_search`-returns-`false` trap.
2. `count(exists) !== messages` ⇒ `refused = 'count-mismatch'`. An empty live
   set is believed only when `messages === 0`.
3. Deletion larger than `max(50, 20% of cached)` ⇒ `refused = 'blast-radius'`,
   defer to a cold resync. The floor of 50 keeps small caches correctable.
4. UIDs below `min_new_uid` are discarded, defusing the `<max+1>:*` overshoot.
5. A `null` flag set means "not fetched": cached flags are left alone.


### Phase 4 — IMAP primitives and driver — **DONE**

`MailImap::statusFor()`, `uidSet()`, `overviewRange()`, `attachmentFlags()`, plus
the `sync_folder()` driver and `db_message_flags_map()`. 85 tests in
`tests_imap.php`, driven by `tests_imap_stub.php`, all offline.

Verified by test: an unchanged folder costs **exactly one command**; a cold sync
populates the window; an incremental sync fetches only the new message; an
expunged UID is dropped while a `\Deleted`-but-unexpunged UID is retained and
marked; a UIDVALIDITY change rebuilds from scratch; the backfill is bounded and
is excluded from `changed`.

**`imap_reopen()` returns `true` even when the server answers `NO` to `SELECT`.**
This was the biggest surprise of the phase, and it means the reviewed "abort
unless `selectFolder()` returned true" guard was not implementable as written —
the function could not tell. Measured behaviour:

- `imap_reopen()` → `true`
- `imap_check()->Mailbox` → `"...<no_mailbox>"`, i.e. nothing is selected
- `imap_errors()` → `["SELECT refused"]`

`imap_errors()` costs **zero round trips** and is empty after a successful
reopen (verified across repeated and cross-folder reopens). So `selectFolder()`
now drains the error stack, reopens, and fails if anything was raised. Free, and
it closes guard 5 for every caller at once rather than just `sync_folder`.

Two test-rig lessons worth keeping:

- The stub must serve **concurrent** connections. `$imap = connect()` opens the
  new session before PHP destroys the old one, so a single-connection accept loop
  deadlocks `imap_open()` on the greeting. It now multiplexes with
  `stream_select()`.
- `selectFolder()` memoizes, so re-selecting after a scenario change is a no-op
  and c-client keeps the previous `EXISTS`/`uid_last`. Tests must reconnect
  between scenarios.


### Phase 5 — Render from the cache — **DONE**

`handle_inbox` and `handle_folder` collapse into `render_folder_listing()`, which
calls the new `folder_view_data()`. Verified end to end over real HTTP with a
real session against the stub:

| Render | IMAP commands |
|---|---|
| First visit to a folder (cold cache) | 35 |
| **Every subsequent render** | **0** |
| Page 2, warm | **0** |

Pieces added:

- `cache_rows_to_view()` — pure adapter. The cache stores `from_display` and
  `from_address` separately while the view renders a single `from`, so it falls
  back to the address when there is no display form. Tested.
- `folder_view_data(callable $imapFactory, ...)` — takes a **factory**, not a
  connection, so IMAP is only reached when the cache cannot answer. A test
  injects a factory that throws, proving a warm render never touches it.
- `folder_list_for()` — sidebar from `folder_list_cache` (15 min TTL). This is
  why `imap_for_session()` could only be made *lazy*, not removed: `view_inbox`
  needs the `group` key from `orderFolders()`.
- `MailImap::isTrashFolder()` / `isSentFolder()` are now **static**. They were
  always pure, but as instance methods they forced the render path to hold a
  connection just to classify a folder name.
- Contact harvesting moved into `sync_folder`, where it runs once per message as
  it enters the cache, instead of on every render.

Design points that came out of the blocker list:

- **`pages` comes from `folder_state.total`** (the server's MESSAGES), never from
  the cached row count, or pagination silently truncates the folder. Tested with
  45 messages and a 500-window: 3 pages, all served from cache.
- **A page reaching past the cached window is served live.** Tested with a
  10-message window and 45 messages: page 1 free from cache, page 3 live.
- **A failed cold sync falls back to live IMAP and surfaces a flash error**,
  rather than rendering "No messages here." Tested against a `status_fail` stub.

Known cost, accepted: the **first** visit to a folder is now slower than before
(35 commands vs 8), because the cold sync fetches the window plus a 20-message
attachment backfill. It is a once-per-folder cost and it pays back on the second
render. Phase 6 removes it from the render path entirely by moving the sync
behind a JSON endpoint.

Interim regression to close in Phase 7: `handle_inbox`/`handle_folder` no longer
write to `search_cache`, so local search has fewer rows to match and falls
through to the server-side IMAP `TEXT` search more often. Search still works —
arguably more correctly, since it no longer short-circuits on subject-only rows —
but `db_search_cache` should be repointed at `messages`.


### Phase 6 — Sync endpoint, JS trigger, indicator — **DONE**

`?page=sync` (POST, CSRF, JSON), the `sync_request()` driver, a vanilla-JS
trigger and a sync indicator in the toolbar. Verified end to end over HTTP:

| Action | IMAP commands |
|---|---|
| Cold inbox render | 15 (was 35) |
| Warm render | 0 |
| Sync inside the throttle window | **0** |
| Forced sync, nothing changed | 4 |
| Forced sync, one new message | 4, `changed: 1` |

New pure, tested helpers: `relative_time()`, `sync_should_run()`,
`imap_folder_name_is_safe()`.

- **The throttle is applied before the connection is built.** `sync_request`
  takes a factory and only calls it once a sync is actually due, so a throttled
  request costs no TCP, no TLS and no LOGIN — not just no commands. Tested by
  counting factory invocations.
- **`session_write_close()` after reading the credentials.** `session_start()`
  holds an exclusive lock for the whole request, so a 1–3 s sync would block
  every other tab including the user's next click. Credentials are copied out of
  `$_SESSION` first, then the lock is released, and nothing after that writes to
  the session.
- **Folder names from the request are validated** by `imap_folder_name_is_safe()`
  before they reach `imap_status`/`imap_reopen`. The mailbox spec is
  `{host:port/imap/ssl}Folder`, so `{`/`}` would let a caller redirect the
  connection and CR/LF would let them inject a second IMAP command. Both
  rejected, with no connection opened. Verified over HTTP.
- **One reload per page load**, enforced client-side, so a sync that kept
  reporting changes could never loop the browser.

**The cold-sync cost was fixed properly, not deferred.** Deferring the attachment
backfill to the endpoint did not work: the follow-up sync is throttled, so
paperclips stayed unresolved. And the backfill was expensive *because* the
envelopes had just been cached, which is exactly what defeats c-client's
lookahead. So `overviewRange()` gained a `$withAttachments` mode that resolves
structures ascending **before** the envelope fetch, in the same batched command.
A 40-message cold sync now costs ≤12 commands with zero structures left pending,
and paperclips are correct on the very first paint.

`db_upsert_messages` writes `has_attachment`/`structure_synced` on INSERT while
still never overwriting them on conflict. The bounded backfill remains for rows
cached before this change and for new arrivals fetched by UID range (which cannot
be walked ascending by message number).

**No jQuery.** The CSP is `script-src 'self' 'unsafe-inline'`, so a CDN script is
blocked outright; allowing it would mean adding a third-party origin to the policy
that guards the sanitized-email iframe. The whole feature is ~50 lines of vanilla
JS on top of the `fetch()` helper that already existed, against ~30 KB for jQuery.


### Phase 7 — Keep the cache honest on mutation — **DONE**

Every mutation now updates the cache immediately, so the UI is correct without
waiting for a sync. Verified end to end over HTTP: after opening an unread
message, the list re-renders in **0 IMAP commands** and the row is no longer
bold; after a delete, the row is gone in **0 commands**.

- `cache_invalidate_folder()` — forces a resync while **preserving
  `uidvalidity`**. Clearing it would make the next `sync_decide()` see a changed
  generation and purge the whole folder. It also refuses to create a state row
  for a folder never synced. Both tested.
- `cache_apply_move()` — drops the row from the source and invalidates the
  destination, used by delete / trash / restore.
- `handle_read` sets `unread = false` **and** stores the real body snippet, which
  is what makes local search useful over time.
- `handle_mark_read`, `handle_delete`, `handle_restore`, `handle_empty_trash`,
  `handle_send` all update or invalidate the cache.
- `handle_delete` and `handle_mark_read` now **check `selectFolder()`** and abort.
  A UID delete against the wrong mailbox destroys unrelated mail.

**`FT_PEEK` — a real bug, found by a wire-level test.** `FT_PEEK` appeared nowhere
in the codebase, so `imap_body()`/`imap_fetchbody()` set `\Seen` as a side effect:
replying to, forwarding, or merely **downloading an attachment from** an unread
message silently marked it read. All three fetch sites now pass `FT_PEEK`, and a
test asserts on the wire that every body fetch is `BODY.PEEK[...]`. Marking read
is now only ever explicit, in `handle_read`.

**Search repointed at `messages`**, replacing `search_cache`:

- `db_search_messages()` / `db_count_search_messages()` — real `LIMIT`/`OFFSET`
  and a real count, so search paginates properly. The old path hardcoded
  `pages => 1` and could render 200 rows in one response.
- Results carry **real flags** (the old path hardcoded `unread => false`,
  `has_attachment => false`).
- Matches subject, sender name, sender address, recipient, **and** the body
  snippet of messages that have been opened.
- LIKE wildcards in user input are escaped, so searching `a_b` or `%` matches
  literally instead of acting as a wildcard. Tested.
- **The body-search regression is closed properly.** Local results no longer
  silently short-circuit the server search: when local matches exist the view
  shows a "Search message bodies on the server" link (`?page=search&server=1`),
  which runs the IMAP `TEXT` search. Fast by default, complete on request.
- A local search costs **0 IMAP commands** — it does not even build a connection,
  because `handle_search` now uses a lazy factory.

**"Clear local cache"** in Settings, showing the live header count. It clears
`messages`, `folder_state` and `folder_list_cache` for that user only, and
deliberately leaves `settings` and `contacts` alone. Verified: 29 headers → 0,
and the next render cold-syncs.

Left alone deliberately: `search_cache` and its three now-unused helpers
(`db_cache_email`, `db_search_cache`, `db_remove_cache_uid`) are dead but not
deleted — dropping the table is destructive and still needs a decision (§8.1).
`db_prune_cache` (login) and `db_purge_cache_for_email` (logout) still run against
that table and are now harmless no-ops.


### Phase 8 — deleted
Cron is not a phase; it is a decision not taken. See §8.

---

## 7. Test tooling — **committed**

`tests_imap_stub.php` (wire-level IMAP server, scenario-driven via a JSON file it
re-reads per command) and `tests_imap.php` (85 tests). Wired into `just test`.

It is **not a mock**: c-client opens a real TCP connection and speaks real IMAP,
so tests assert both behaviour and exact round-trip counts. It has now paid for
itself three times over — it caught the 52-vs-27 baseline error, the
`imap_reopen()`-returns-true bug, and the lookahead ordering that took the render
from 28 commands to 8. None of those were reachable by reading the code.

Caveats learned the hard way, all encoded in the file's comments:

- Answer **only what was asked**. An over-answering stub returns BODYSTRUCTURE on
  every FETCH, c-client caches it, and the N+1 under measurement vanishes.
- Serve **concurrent** connections, or the tests deadlock.
- Truncating logged commands for display hides the very detail that matters —
  the ordering discovery was initially masked by a 70-character cut.

---

## 8. Risks and open decisions

1. **Drop `search_cache`?** Now unused (Phase 7), so this is a one-line migration
   step 3 plus deleting three dead helpers. Still destructive to on-disk data, so
   it needs an explicit yes.
2. **Keep the cache after logout?** Required for the cache to be worth anything.
   Consequences the first draft understated:
   - Under a web server the DB is owned by the PHP-FPM uid, so *any* code
     running as that uid — every other app on the box — can read every cached
     subject, sender and snippet. `chmod 0600` does not help there.
   - The DB defaults to `__DIR__ . '/emmmail.sqlite'`, i.e. **inside the
     document root**. This should become a hard requirement to move it out, or a
     deny rule, not a README suggestion.
   - `README.md:161` and `docs/memory.md` both claim "no message content is
     stored". Already false (`handle_read` stores 500 chars); this makes it
     emphatically false. Both need updating, along with the table count and the
     page list.
3. **Cache depth.** 500 newest per folder; deeper pages go live.
4. **Full reconcile interval.** Every ~60 s of activity, plus on manual refresh.
5. **Cron.** Needs its own plan. Note the first draft mis-stated the blocker:
   the encryption key is **server-global**, not session-scoped, so a CLI driver
   on the same host *can* decrypt. The real obstacle is session-file lifetime,
   which is a smaller problem than assumed — but storing a credential at rest
   still reverses a deliberate decision.
6. **Disk growth is unbounded and that is deliberate.** The cold sync caps the
   initial window at 500 per folder, but new arrivals accumulate with no trimming.
   At ~500 bytes/message that is ~10 MB per 20k messages, and a local index of
   everything is the point — desktop clients do the same. "Clear local cache" is
   the escape hatch. If a cap is ever added, it must update `cached_rows` in the
   same transaction and must never lower `maxCachedUid`: the cache has to stay a
   contiguous newest-N window, or `maxCachedUid+1:*` skips messages permanently.
7. **SQLite is not a bottleneck.** Measured at 50k rows: page 1 in 0.018 ms,
   page 45 in 0.037 ms, `LIKE` over 4 columns in 0.344 ms. Stop thinking about
   it.
8. **Not addressed, deliberately.** The TCP probe in `MailImap::__construct`
   costs a whole extra handshake (~40 ms) and `imap_timeout(IMAP_OPENTIMEOUT, 5)`
   would replace it — but the probe exists because c-client hung forever and
   segfaulted PHP at shutdown on an unreachable host. Not worth changing without
   a live test against an unreachable host.
9. **Per-row output boilerplate.** ~660 of 1,485 bytes per message row is two
   hidden CSRF inputs and inline `<svg><use>` blocks. Replacing the per-row
   `<form>`s with the existing `post()` helper saves ~10 KB per page — more than
   removing the whole icon sprite. gzip already covers most of it.
