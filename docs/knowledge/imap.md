# ext-imap / c-client 2007f

## The connection probe
`imap_open()` blocks forever on an unreachable host and segfaults PHP at shutdown. c-client
ignores `default_socket_timeout` for TCP connect, so no ini setting helps. Hence the bounded
5s `stream_socket_client` probe in `MailImap::__construct`.

It costs an extra handshake (~40ms) and renders identically. **Do not remove it** without a
live test against an unreachable host — there is no other way to bound that connect.

## Search
- `imap_search` returns **`false` for "no matches"**, indistinguishable from a failure.
  Normalising it to `[]` inside a reconcile is a mass delete. It must be `?array`.
- `imap_search('ALL')` is resolved **locally** with ⌈N/1000⌉ FETCHes. `'UNDELETED'` goes
  server-side as 1 command. The criteria parser has no `UID`/`NOT`/`OR`/`HEADER`.

## Status and reopen
- `imap_status` degrades to `EXAMINE` on pre-rev1 servers, and `EXAMINE` *strips*
  uidnext/uidvalidity. Read via `property_exists()`, never `?? 0`.
- **`imap_reopen()` returns TRUE when the server answers NO to SELECT**, leaving the stream
  with no mailbox selected. `imap_errors()` catches that at zero round-trip cost.
- `imap_errors()` must be **drained** after a failed CREATE/DELETE, or c-client's queued
  error is dumped at PHP shutdown against whatever ran last.

## Batching
**No CONDSTORE/QRESYNC. No batched BODYSTRUCTURE API** — but c-client's lookahead *does* batch
it, if the structures are requested ASCENDING and BEFORE the envelopes. That was the single
biggest performance win in the project (21 commands/page -> 1).

A bulk action is **one IMAP command for the whole batch**, not N. c-client takes a
comma-joined UID list, so `markManyAsRead()` is one `UID STORE`, and `moveManyToFolder()`
is one `COPY` + one `UID STORE` + one expunge wipe — still three round trips for 200
messages. Two traps, both pinned by the `tests/imap.php` traces:

- **No MOVE.** c-client 2007f has no `imap_mail_move`, so a batch move is
  COPY-then-wipe. The wipe is `imap_delete` + `imap_expunge` per the target folder,
  which c-client's move helper does in one loop. Never issue that wipe for an
  **empty** UID list: it is a mass-delete primitive with nothing to delete.
- **`imap_setflag_full` with a UID list needs `ST_UID`.** Without the flag the
  comma-joined numbers are read as sequence numbers, and on a folder whose UIDs
  differ from its sequence numbers the batch marks the wrong messages.

The four batch methods return **`int`, not `bool`** (`markManyAsRead`,
`deleteManyByUid`, `moveManyToTrash`, `moveManyToFolder`). The count is the answer:
the client removes exactly the rows the server moved, and a short count is
treated as a refusal. A `bool` here is a different contract, not a weaker truth
value — `if ($count > 0)` against `true` compares against 0 and skips every cache
write, so the list keeps rendering rows the server already moved. Test-guarded by
return type.

**200 cache writes per request is the new cost.** `cache_mark_read` is one
prepared UPDATE per UID plus a counter bump when the flag flips, and it is not
covered by the "budget your round trips" rule above: it is local SQLite, not
IMAP. The two `cache_invalidate_folder()` calls stay *outside* the loop.

## Round trips
No pipelining: every `imap_*` call is one synchronous round trip. Budget them.

## References rides the overview FETCH
`imap_fetch_overview()` returns `message_id`, `in_reply_to` **and** `references`. `References`
is not in the ENVELOPE, but c-client requests `BODY.PEEK[HEADER.FIELDS (… References)]` in the
**same FETCH** as the envelope, so reading it adds no command. Verified against the real
extension: a page costs exactly one command. `overviewRange()` and `fetchOverviews()` export
all three.

The trap is the test stub, not the wire: `tests/_imap_stub.php` answered any `BODY[…]` section
with a body string, so `references` came back empty. It answers a `HEADER.FIELDS` section with a
real `References:` line now, keyed by `references_map` per uid. A stub that does not answer it
makes the feature look absent, never broken.

## Per-message rules
- Body fetches need `FT_PEEK`, or reading/downloading marks the message read.
- Deletes go by **UID**, never sequence number, and must check `selectFolder()`.

## `\Noselect`
`\Noselect` mailboxes are hierarchy nodes; STATUS and SELECT both fail on them.
`folder_list_for()` filters them on the read path, but `getFolders()` must still return them,
because special-folder detection needs to see the tree.

## Sync and reconcile
- Sync invariant: `count(UNDELETED) + count(DELETED) === MESSAGES`. Guards: an unknown set
  deletes nothing; a count mismatch refuses; a large delta is capped at `max(50, 20%)`.
- **"No cached rows" is not "nothing cached."** Cold is
  `$state === null || ($cached === 0 && $state['total'] > 0)`. The live fallback is gated on
  `!$knownEmpty`, because a *missing* state row means the total is unknown and must go live.
  That is what stops a failed STATUS from showing an empty mailbox.
- Write `folder_state` from the **opening** STATUS, never from `max(uid)+1` of what was
  fetched: an arrival between STATUS and FETCH would be recorded as already-seen and lost.
  RFC 3501 §6.3.10 also warns that STATUS on the *selected* mailbox is not useful.
- `?page=sync` is throttled before connecting. `cache_invalidate_folder()` /
  `cache_apply_move()` run on every mutation.
- `folder_view_data()` takes an IMAP **factory**, not a connection, so nothing connects
  unless the cache cannot answer the page.

## Folder counts
**Sidebar counters live in `folder_counts`, never in `folder_state`.** `sync_decide()` compares
`folder_state.unseen`, so a sweep or a +/-1 written there makes a changed folder look
unchanged. A `folder_state` row for a never-synced folder also reads as "known empty".

- `sync_folder()` writes the open folder's counts from its STATUS.
- `counts_sweep()` STATUSes the others (60s per folder) on the sync's shared lazy connection.
- `cache_mark_read()` bumps a counter only when the cached flag actually flips.
- The Drafts badge is the total, because drafts are `\Seen`.

## The folder list cache has two readers, and only one honours the TTL

`db_get_folder_list()` takes the TTL per call, so the split is visible in the callers:

- **`folder_list_for()`** — the folder listings. Applies
  `FOLDER_LIST_TTL_SECONDS`, re-LISTs on a miss, writes the result back. It already
  holds a connection, so it is the only path that can act on "the list may have changed".
- **`cached_folder_list()`** — the sidebar on the compose and settings pages, the
  reader's Move picker, `<body data-kb-map>` and the onboarding dialog. Passes an
  unbounded TTL and **never connects**.

That second reader used to apply the TTL too, which was the bug: a page with no
connection behind it turned "the cache is 16 minutes old" into "there are no folders".
The sidebar rendered a bare heading over an empty `<ul>`, and `kb_folder_map()` returned
`[]`, so `render_page()` dropped `data-kb-map` and every `g X` toasted `No such folder.`

**Stale beats empty.** Chrome needs *a* folder list, not a fresh one. A folder created
from another client appears on the next inbox visit, which is where the connection
already is. `view_sidebar()` says `Folders are unavailable` when the list really is
empty, so a genuine miss reads as a condition rather than as a broken page.

## Never cache an empty LIST, and never cache a broken one

`cache_refresh_folder_list()` must not store an empty result — one failed round trip
would blank the sidebar for the whole TTL.

`db_set_folder_list()` must also refuse a payload `json_encode()` returns `false` for,
which is what a non-UTF-8 folder name produces. Bound unchecked, that `false` was stored
as `''`, `json_decode('')` is `null`, and every read missed for ever: a silent failure no
refresh could clear. `JSON_INVALID_UTF8_SUBSTITUTE` is **not** the fix — a substituted
name is a name that cannot be SELECTed, so it trades a loud miss for a quiet wrong folder.

Known gap: a server that really does send a non-UTF-8 folder name makes every write
fail, so the listings re-LIST on each visit and the sidebar stays empty. Honest, but not
fixed.

## Folder ordering
`MailImap::classifyFolder()` ranks Inbox 1, Drafts 2, Sent 3, Archive 4 (group `primary`),
Trash 5. `view_sidebar()` draws the divider purely on a group change, so putting Archive in
`primary` is what places it after Spam and above the separator. The folder list is cached for
`FOLDER_LIST_TTL_SECONDS` (15 min) **with** the `group` key — without it an open session
keeps the old order until the cache expires.

Known gap, pinned by a test: nested `INBOX.Deleted Items` is not recognised as Trash
anywhere. The cost of that gap rose with bulk delete: `moveManyToTrash()` falls back
to a **hard delete** when `findTrashFolder()` returns null, so one unrecognised Trash
mailbox now means up to 200 messages gone with no undo.

The confirm copy no longer hides that. `MailImap::findTrashFolderIn($folders, $map)`
is the single resolver: `findTrashFolder()` calls it with the live list, and the
delete copy calls it with the list the render path already holds. `delete_is_permanent()`
is `isTrashFolder($folder) || findTrashFolderIn(...) === null`, so the copy warns
"permanently" in exactly the two cases the handler hard-deletes. An empty or missing
list also warns: nothing proves a Trash exists. Only the detection remains unfixed.
