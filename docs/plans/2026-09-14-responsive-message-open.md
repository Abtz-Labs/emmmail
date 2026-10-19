# Opening a message reacts immediately

status: done

## Why

Clicking a message is a plain link to `?page=read`, and that route is the most
expensive request in the app: TCP probe, TLS, IMAP LOGIN, `SELECT`, `imap_msgno`,
`imap_headerinfo`, `imap_fetchstructure`, body fetch, attachments, then
`markAsReadByUid` — all before the first byte of HTML. The folder list is served
from the SQLite cache and feels fast; the reader has nothing to paint for the
whole round trip, so the click looks ignored.

Two separate defects: **no acknowledgment** of the click, and **no progressive
paint** of the reader.

## Measured, not assumed

`zlib.output_compression` is on for every page (index.php:4624) and it **swallows
`ob_flush()`/`flush()`**. Probe against `php -S`, response with a 2s stall in the
middle:

| Accept-Encoding | first bytes | rest |
|---|---|---|
| `identity` | +0.00s | +2.01s |
| `gzip` | — | +2.01s (everything) |

So HTML streaming would cost gzip on the largest page in the app, and would also
be at the mercy of nginx/FPM `fastcgi_buffering` in production. Rejected in
favour of a shell plus one fetch, which keeps gzip and cannot be buffered away.

## Decisions

- **`?page=read` never touches IMAP.** It renders chrome, sidebar, action bar,
  avatar, subject and meta from the `messages` cache, plus a skeleton body. Small,
  gzipped, single-digit ms.
- **One `fetch` does the IMAP work**, `?page=readBody`, returning the body HTML as
  JSON. No router, no `pushState`, no prefetch: it is still an ordinary
  navigation, so Back, reload and deep links behave exactly as today.
- **Shell values are never overwritten** by the fetch. Subject, From, To and Date
  come from the same cached row the list row was drawn from, so nothing changes
  text under the reader's eyes. Only the skeleton is replaced.
- **`&full=1` keeps today's server-rendered path**, used by `<noscript>`, by any
  fetch failure, and whenever the message is not in the cache.
- **Marking read moves to `?page=readBody`**, since that is where the IMAP
  connection now lives.
- Reply-vs-reply-all primacy needs `Cc`, which is not cached. The shell renders
  Reply as primary and the fetch flips it when the answer says reply-all leads.

## Steps

| # | Change | Verification |
|---|---|---|
| 1 | `db_get_message($email, $folder, $uid): ?array` — one cached row, hydrated | unit: hit and miss |
| 2 | Split `view_read()` into `view_read_actions()`, `view_read_headline()`, `view_read_content()` (meta + attachments + images CTA + iframe). `view_read()` keeps its exact output | unit: existing reader assertions unchanged |
| 3 | `view_read_shell(array $head, ...)`: actions + headline + `.read-pending` skeleton carrying `data-body-url`, and a `<noscript>` link to `&full=1`. No iframe | unit: markup, and no `<iframe` |
| 4 | `handle_read()` serves the shell from the cache; falls back to the full render when the row is missing or `full=1` | unit: pure decision `read_wants_full()` |
| 5 | `handle_read_body()` + `?page=readBody`: IMAP fetch, mark read, cache snippet, `{ok, html, reply_all_leads}`; `{ok:false, message}` on failure | unit: payload builder from a canned email |
| 6 | JS: fetch `data-body-url`, swap the skeleton, flip reply primacy, and on any failure navigate to `&full=1` | js: swap, error branch, network branch |
| 7 | JS: click acknowledgment — row spinner + top progress bar, skipped for modified clicks, cleared on `pageshow` (BFCache) | js: each branch |
| 8 | CSS: skeleton shimmer, `.nav-progress`, `.mail.opening` | unit: rules exist |
| 9 | README + memory | read back |

## Risks

- **A stuck skeleton is worse than a slow page.** Every failure path — bad JSON, a
  dead session redirecting to login, an offline network — must end in a normal
  navigation to `&full=1`, never in a spinner that never resolves.
- The fetch runs on a page that JS-off users cannot complete: `<noscript>` must be
  in the shell, not an afterthought.
- `db_get_message()` can return a row for a UID the server has since expunged. The
  shell will then paint a message that the fetch reports as gone; the error branch
  has to offer Back, not a blank card.
- Cached `from_display` comes from `imap_fetch_overview`, the live reader from
  `imap_headerinfo`. They agree in practice; the shell shows the cached one and
  the fetch never rewrites it, so a disagreement cannot flicker.

## Outcome

Measured against a canned message on `php -S`, with the body endpoint stalled 1.8s
to stand in for a mailbox:

| | Before | After |
|---|---|---|
| first byte of the reader | after the whole IMAP round trip | **13ms** (one SQLite read) |
| what is on screen while waiting | nothing | toolbar, subject, sender, To, Date, skeleton |
| click acknowledgment | none | row spinner + progress bar, same frame |
| gzip on the read page | on | still on |

- `?page=read` does no IMAP at all; `?page=readBody` returns 523 bytes of content
  HTML for that message and pays for the connection.
- Every failure exits to `&full=1`, which is byte-for-byte the old page.
- Splitting `view_read()` changed no output: the existing reader assertions passed
  untouched, which is what made the split safe to do first.
- Two things this surfaced that were not in the plan:
  - **A race the shell created.** The toolbar is live before the body fetch marks
    the message read, so `markUnreadBack()` had to queue behind that fetch or the
    server applied read *after* unread and silently lost the user's intent.
  - **The js harness lied about network failures**: its fetch stub threw
    synchronously, which no real fetch does, so a `.catch()` branch could have
    looked covered while being unreachable. It returns a rejected promise now.
- Suite: **1244 passed, 0 failed**, 2 live suites skipped.

## Not done, deliberately

- Prefetch on hover, and the client router with `pushState`: rejected with the
  original C option and still not needed. Back is a normal navigation.
- The IMAP latency itself is untouched: the redundant TCP probe in
  `MailImap::__construct` and body caching are still on the table (option D).

## Follow-up (same day): the switch

The instant reader worked, and that is what made the row spinner invisible -- the
wait it covers is gone. Rather than delay navigation to show it (which buys
nothing: see below), the delivery is now one switch, `READ_INSTANT_SHELL`, set to
`false` so the spinner-then-whole-page version can be lived with first.

- **A prefetch cannot survive the navigation.** A `fetch()` started in the list is
  aborted when the browser tears the document down, so an artificial delay would
  add latency and preload nothing. Carrying it across needs a cacheable `readBody`
  (mail bodies in the browser cache) or a service worker, which a single-file app
  cannot ship. And `readBody` marks the message read, so prefetching on hover would
  mark mail read as the pointer passes over it.
- Free feedback instead: `.mail:active` / `.mail.unread:active` answer the press on
  pointer-down. Both selectors are required, because `.mail.unread:hover` (0,3,0)
  outranks a lone `.mail:active` (0,2,0).
- The skeleton is livelier for when the switch goes back on: the shimmer is
  staggered per line (`nth-child` delays) with an accent band in the gradient, the
  waiting note carries a spinning `tabler-progress`, and `.nav-progress` runs for
  as long as the body fetch is actually in flight.
- Suite: **1252 passed, 0 failed**, 2 live suites skipped.

## Reversal (same day): the instant reader was removed

Lived with, liked, and then dropped in favour of the simpler behaviour: the
spinner on the row plus a whole message page. The instant shell, `?page=readBody`,
`READ_INSTANT_SHELL`, `db_get_message()`, the `view_read()` split and the skeleton
CSS are all gone -- not left behind a flag, because two ways to open a message is
one too many. `git log` holds the implementation if it is ever wanted.

What survives, and is what this plan actually shipped:

- `.mail:active` / `.mail.unread:active` — the press answered on pointer-down.
- `.mail.opening` — the avatar becomes a spinner for the length of the navigation.
- `.nav-progress` — an indeterminate bar in the chrome, cleared on `pageshow`.
- A bug found on review: the spinner arc was painted in `var(--accent)`, which **is**
  two of the eight avatar tones (av-0 nord10, av-6 nord8), so it was invisible on
  those senders. It now sits on an opaque cap in the row's own colour.

Kept as knowledge, not code: `zlib.output_compression` swallows `flush()`, and a
prefetch cannot survive a full-page navigation. Both are in `docs/memory.md`.

- Suite: **1188 passed, 0 failed**, 2 live suites skipped.

## Extension: the reader's actions (same day)

The list was reactive and the reader was not: every control in it is a round trip
(a reply has to fetch the message to quote it; filing it talks to two mailboxes),
and none of them said anything. Same vocabulary as the list, applied to controls:

- `markBusy()` / `clearBusy()` — the clicked control keeps its place and swaps its
  own icon for `#tabler-progress`, with the original kept in `data-icon-was`.
  `pointer-events:none` while busy is what stops a second Delete being fired.
- `.btn:active` / `.icon-btn:active` — the press, answered before any request.
- Dialog-first controls (`[data-move]`, `[data-delete]`) are skipped on click and
  marked by their confirm paths instead, so a cancelled dialog never leaves a
  spinner. `readerControl()` scopes that lookup to `.actions-bar`, because a list
  page has one `[data-delete]` per row.
- Attachment links stay out: a download does not navigate, so nothing could clear.

A bug found while wiring it: **`rowError()` had nowhere to write in the reader.**
Only `view_inbox()` rendered `.row-error`, so a refused Spam, Move or Delete from a
message was silent -- and with a busy state added, it would have spun for ever. The
reader renders the box now and every failure path calls `clearBusy()`.

- Suite: **1220 passed, 0 failed**, 2 live suites skipped.

## Extension: search (same day)

Search was the last surface with no answer to a click. Rather than add a seventh
near-identical binding, the round-trip surfaces are now one list:

```js
['.actions-bar', '.images-cta', '.search', '.hint'].forEach(...)   // a + button
$('.search').on('submit', ...)                                    // Enter
```

- **The submit binding is the point.** Enter submits the search without ever
  clicking the button, which is how search is normally used, so a click-only
  binding would have missed the common case entirely.
- `.hint` is the "search message bodies on the server" offer -- a full IMAP SEARCH,
  and the slowest of the three.
- No stuck-spinner risk: the query is not `required` and an empty one redirects
  server-side, so the form always navigates.
- `markBusy()`'s early return is now load-bearing for a second reason: a mouse user
  fires click *and* submit, and the second call must not record the spinner it just
  installed as the icon to restore. Pinned by a test.
- Suite: **1235 passed, 0 failed**, 2 live suites skipped.

## Extension: the composer (same day)

Send is the longest wait in the app -- an SMTP conversation plus the upload of any
attachment -- and it had no feedback at all. `.compose-form` (a new hook on the
form) binds `submit`, and `markBusy(ev.submitter || .btn-primary)` spins whichever
button was pressed.

- **`ev.submitter` matters here**, unlike search: Save draft and Send are two submit
  buttons in one form, and spinning Send for an IMAP append would be a lie. Safari
  before 15.4 has no `submitter`, so the primary action is the fallback.
- **Validation makes `submit` safe**: `to` and `subject` are `required`, and a
  submit the browser refuses never fires the event -- so an empty form cannot leave
  Send spinning. Save draft carries `formnovalidate`, so it always fires. Both are
  pinned by tests, because either could be changed innocently later.
- Untouched, deliberately: attaching is a `<label>` over a file input and Cc/Bcc are
  `type=button` -- neither is a round trip, and a test asserts the attach control is
  not referenced by the client.
- Suite: **1249 passed, 0 failed**, 2 live suites skipped.
