# Read and sync performance

**Status:** in progress (code complete, all four suites green; browser pass not run)
**Date:** 2026-11-06

## Result (measured against `tests/_imap_stub.php`, commands after LOGIN)

| Path | Before | After |
|---|---|---|
| Open one message, cache miss | 7–8 | **4** (`SELECT`, uid→msgno, envelope+structure, one body) + `STORE` |
| Open one message, cache hit, already read | 7–8 + connection | **0, no connection** |
| Open a 10-message conversation, miss | 37 | **4** + one batched `STORE` |
| Poll, unchanged, within 5 min of a reconcile | 9 (every poll) | **1** `STATUS` |
| Per navigation | ~190 KB inline CSS+JS | two immutable-cached assets |

## Goal

Opening a message or a conversation, and syncing, should be as fast as the listing already
is. Measured against `tests/_imap_stub.php` before this plan (commands after LOGIN):

| Path | Before |
|---|---|
| Open one message | 7 (`NOOP`, `SELECT`, uid→msgno, envelope, `BODYSTRUCTURE`, body, `STORE`); 8 on multipart/alternative |
| Open a 10-message conversation | 37 |
| Poll, unchanged, ≥60 s since reconcile | 9 (the poll period equals the reconcile interval, so nearly every poll) |

Every request also pays the connection: TCP probe, TLS, `LOGIN`, `CAPABILITY`.

## Decisions

- **Bodies are cached, encrypted at rest** with a key derived from the mail password at
  login (`sodium_crypto_pwhash`, interactive limits), held in the session like the password
  (`$_SESSION['body_key']`, `session_encrypt`ed). The cache survives logout as opaque blobs; a
  copied database is useless without the password; a changed password turns old rows into
  misses that the next fetch overwrites. No sodium → no body cache, never a plain fallback.
  Lifts the README limitation "bodies are never stored" (test + README + plan, together).
- **Write-through and bounded prefetch.** Every body fetched for display is stored. The
  background poll prefetches the newest `SYNC_BODY_PREFETCH` (10) arrivals on the connection it
  already holds. The render-path cold sync prefetches nothing (like `backfill => 0`).
- **Budget.** `BODY_CACHE_MAX_ROWS` 300 and `BODY_CACHE_MAX_TOTAL` 50 MB per user, oldest
  `fetched_at` evicted; a body over `BODY_CACHE_MAX_BYTES` (1 MB) is served but not stored.
  Attachments are never cached, only their part list.
- **The cached body is the decoded raw message**, not the sanitized output: `sanitize_html`
  and the remote-image policy run at render exactly as before.
- **A body row dies with its header row.** `db_delete_messages`, `cache_apply_move`
  (through it), the UIDVALIDITY purge and Clear local cache all cascade.
- **Marking read on a cache hit** is skipped when the cached row is already read, else done
  after the response (`fastcgi_finish_request()` where available).

## Steps

1. `MailImap::getEmailByUid($uid, $allParts = true)`: `imap_fetchstructure` before
   `imap_headerinfo` (one FETCH); `$allParts = false` fetches one text part, HTML preferred.
   Constructor takes an optional mailbox and opens it directly (no `NOOP` + `SELECT`), with a
   half-open fallback when the mailbox cannot be selected. `imap_for_session()` passes the
   folder. — imap tests pin the command counts.
2. `db_set_message_flags` loop in one transaction; `PRAGMA synchronous=NORMAL` (WAL).
   `SYNC_FULL_INTERVAL_SECONDS` 60 → 300.
3. `?page=asset&k=css|js&v=<hash>` with immutable caching; `render_page()` links them.
4. Body cache: schema v5 `message_bodies`, `body_cache_*`, login key, read/thread/reply/forward
   read through it, sync prefetch, Settings copy and counts, README.
5. Conversation lazy-load: `handle_thread()` fetches only the open (newest) member; the rest
   render from the header cache with `data-lazy` and load through `?page=read&ajax=1` on
   `toggle`.
6. Post-sync refresh swaps the mailbox in place (`loadMailbox`) instead of reloading; falls
   back to a reload. `?page=inbox&ajax=1` answers like `?page=folder`.
7. Docs: knowledge (imap.md, security.md, frontend.md), memory.md, README.

## Owed (manual browser pass)

- Open a message cold and warm; a conversation with collapsed members expanding; Show images
  from an expanded member; reply quoting from a cached HTML-only body.
- Settings: body count and size, Clear local cache wipes them.
- Login with sodium present; a changed password after a cached session.
- Post-sync in-place refresh on `?page=inbox` and on `?page=folder&folder=X`.
- Asset caching headers in the Network tab; a deploy changes `v=`.
