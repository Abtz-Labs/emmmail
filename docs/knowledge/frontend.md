# Front end

## The `$` toolkit
A zero-dep jQuery-like toolkit lives in `js_inline()` on `window.$`. Chainable
`.each`/`.on` (including delegation), plus class/text/val/css/attr/data/show/hide. Statics:
`$.ready`, `$.get`, `$.post`, `$.param`, `$.escapeHtml`, `$.token`.

Three APIs are **banned and test-guarded**:

- **No `getElementById`** — use `$` with class selectors.
- **No per-page inline `<script>`** — everything goes through `js_inline()`.
- **No native `confirm()`** — use the modal + `autofocus` pattern (`.modal.hidden`).

`win` is the globals namespace: `rowAction`, `toggleRead`, `markUnreadBack`, `confirmDelete`,
`confirmEmpty`, `confirmClearCache`, `moveTo`, `newFolder`, `deleteFolder`, `toast`.

All `js_inline()` code must stay **stub-guarded**: no bare `localStorage`, `matchMedia`,
`documentElement` or `body`, because the js suite runs the same source against a Node stub.

## One vocabulary for "this costs a round trip"
- `.nav-progress` — a sticky bar in the chrome.
- `.mail.opening` — a clicked list row; its avatar becomes a spinner.
- `.btn.busy` / `.icon-btn.busy` — a clicked control. Its own `<use href>` is swapped to
  `#tabler-progress` and the original is kept in `data-icon-was`.
- `:active` — the press itself.

`markBusy()` and `clearBusy()` (plus `kbBusy()` for keyboard navigation) are the **only**
entry points.

- **`pointer-events:none` on `.busy` is load-bearing.** It is what stops a second Delete
  being fired into the same message while the first is in flight.
- **Every failure path calls `clearBusy()`.** A control that spins for ever is worse than the
  wait it was reporting.
- A spinner on a tinted avatar needs an **opaque cap** (`.mail.opening .mail-avatar::before`):
  the eight avatar tones include the accent itself (av-0 is nord10, av-6 is nord8), so an arc
  in `var(--accent)` was invisible on those senders and low-contrast on the rest — it read as
  an empty coloured circle.

## The bound surfaces
One list: `['.actions-bar', '.images-cta', '.search', '.hint']`, each bound for `a` and
`button`. `.search` and `.compose-form` also bind **`submit`**, because Enter never clicks the
button and that is how both are actually used.

`.attachments` and `.mail-list` are deliberately **absent**: a download does not navigate, so
nothing would ever clear the spinner, and rows spin their own avatar.

`submit` is safe on validated forms — a submit the browser refuses (empty `to`/`subject`)
never fires the event, so a validation error cannot leave a button spinning.
`ev.submitter` names the pressed button, which matters because Save draft is not a Send;
`.btn-primary` is the fallback for Safari before 15.4.

## Dialog-first controls must not claim to work
Move and Delete open a picker that can be cancelled, so `readerActionClick()` skips
`[data-move]` / `[data-delete]`, and their confirm paths call
`markBusy(readerControl(...))` themselves. `readerControl()` is scoped to `.actions-bar`,
because a list page has one `[data-delete]` per row.

## A JS/DOM contract needs a PHP-side test too
The js suite builds its own fixture and cannot see the real markup. Two bugs lived entirely in
that gap: the sync spinner (CSS named `.btn.sync-btn.syncing` while the JS added `syncing` to
the `.sync` wrapper) and a dead sync button (the markup had dropped `.sync[data-folder]` and
`.sync-text`, so `$('.sync')` was empty and nothing ever synced).

**When JS toggles a class, the CSS must name the element the JS touches.**

## Markup exists for the JS, not the other way round
`.mail[data-uid]`, `.mail-count`, `[data-read-toggle]` and a pre-rendered hidden `.row-error`
are in the markup because the JS needs them. The js stub's `matches()` has **no descendant
combinator and no `:not()`**, so selectors are written flat or two-step
(`kbBack`, `kbFocusSearch`, `.pagination a`).

`rowError()` had nowhere to write in the reader — only `view_inbox()` rendered `.row-error` —
so a refused Spam/Move/Delete from a message was *silent*. The reader renders the box now.

## Optimistic actions
`rowAction(uid, folder, url, mode)` patches the row, then posts with `ajax=1`. The `remove`
mode hides via `.row-gone` and detaches only once confirmed, so rollback is one class.
`respond_mutation()` / `request_wants_json()` answer JSON to that client and
flash+redirect to a plain form post.

`readerControl()` and `rowError()` are the reader-side seams; `wireMailbox()` re-binds
everything after a folder swap, because **element-bound listeners die with the swap**.

## Bulk selection
State lives in one module-private `pickSet`, keyed by UID. `pickUids()` sorts
numerically before anything leaves the client: `Object.keys()` on integer-like keys
already enumerates ascending, but that is a spec detail, and display order is a
newest-first listing, which is *not* UID order. The js fixture rows are
deliberately `1003, 1001, 1002` so the encoded body can only be right because of a
real sort.

- **The bar's visibility is a CSS fact.** `.mailbox:has(.bulk-bar:not(.hidden))`
  reveals the pickers; the client only maintains the `.hidden` class and never asks
  whether the bar is up. Two layers that can disagree is the bug this avoids.
- **"Is the bar up?" is never a `querySelector` for `.hidden`.** The js stub's
  `matches()` cannot parse `:not()`, so such a predicate returns everything.
- **Every delegated binding hangs off `.mailbox`**, which is also what a folder
  swap replaces. The js `buildList()` fixture had to grow a real `<section
  class="mailbox">` because a list with no mailbox is a list nothing is listening to.
- **`kbEditable()` exempts a row picker** from the typing guard. A checkbox accepts
  no text, so only Space and Enter count as editable; otherwise j/k/x stop working
  right after a click, which is when they are needed.
- **`pickReset()` runs first in `wireMailbox()`** and again on `pageshow`, because a
  swap or a restored page leaves a `pickSet` of UIDs that are no longer on screen.
- **Space is `x` for a cursor that exists.** `kbSpaceSelect()` acts only with a cursor,
  and not on a focused button, `<summary>` or a link other than `.mail-main`. There
  Space scrolls the page or presses the control, and taking that away breaks the page
  for everybody who never pressed j. No shortcut used Space before.

### Optimistic bulk actions
`bulkAction(action, unread)` takes an **explicit direction**, not a per-row toggle:
on a mixed set a toggle marks the already-read rows the other way, painted before
the request goes out. `bulkRemove(uids, folder, url, extra)` is the removal half,
shared by move and delete.

**A flag change removes nothing.** Move and delete take the row out of the folder,
so they remove it. Marking read or unread does not, and its success path must not:
the row list was copied from `bulkRemove()` and every ticked row vanished until the
next reload. Only the selection is dropped. `.mail-count` is the folder total, which
a flag does not move, so `bumpCount()` belongs to the removal path alone.

**`pickReset()` unchecks the row boxes, not only the head one.** `checked` is the
box's own DOM state, so dropping `.picked` alone left `checked:true` on a deselected
row, and the next hover painted that stale tick.

**The selection goes with the repaint, not the reply.** `bulkAction()` calls
`pickReset()` right after it paints the rows. Waiting for the round trip left rows
showing their new state still ticked for seconds. `undo()` does not reset again: by
the time a refusal lands, a tick on screen is a new selection. js S71 and S82.

**An icon lookup is scoped to its control, never to the row.** A row's first `<use>`
is the subject's paperclip when the message has an attachment, so `setReadIcon()`
reading `row.querySelectorAll('use')[0]` turned the paperclip into an envelope. It
now reads the `<use>` inside `[data-read-toggle]`. js scenario S81.

**`pickReset()` clears `indeterminate`, not just `checked`.** The tri-state head is a
DOM property, so un-ticking the box leaves its dash on screen and the head looks
half-selected with nothing picked.

Three rules that are not obvious and all have a js scenario:

- **Badge deltas sum, they do not collapse.** `bumpBadge()` returns what it
  actually applied (clamped at 0), so the per-row deltas sum to one exact undo. With
  an unseen badge of 12 and five rows removed of which three were unread, the server
  says 9; a single `bumpBadge(-5)` paints 7. Each row also bumps the kind it can
  actually move — `'unseen'` for unread, `'total'` otherwise — so one summed call
  cannot express the split at all.
- **The header total steps down per row, not once per batch.** A header still
  reading the old total while rows collapse is drift, and the undo has to reverse
  it.

A response with `ok:false` **and** a short `count` both roll back every row, both
counters and the selection — they are different lies, and a half-applied rollback
is worse than no optimistic patch, because the next sync cannot tell a real change
from a ghost one.

## Instant folder switch
A sidebar click on a listing fetches `?page=folder&ajax=1` and swaps only
`<section class="mailbox">` (`loadMailbox()`). `history.pushState` makes Back work, and
`popstate` swaps listings / full-navigates everything else. The poll chain refreshes new
mail by reloading the current URL (below), not by re-fetching the listing.

`view_mailbox()` is the standalone section (the AJAX payload); `view_inbox()` is sidebar + it.
Interception is gated on `doc.querySelector('.sync')`, so the reader, compose and settings
still full-navigate.

`wireMailbox()` re-binds after every swap: the round-trip list, `.search` submit, `.mail-list`
ack, pagination (per-anchor, because `$('.pagination a')` is untestable in the stub),
`#move-dialog`, and `wireSync()` (the chip only, and a fresh chip is a new element).

## The poll chain
New mail has to reach a tab that is just sitting there, so the chain polls on **every**
signed-in page. The period rides in `<header class="topbar" data-period>`, the one element
`render_page()` puts on every signed-in page, so the login page (no topbar) and the
`auto_poll` switch turned off (`data-period="0"`) both leave the chain unscheduled.

**The interval is `SYNC_CLIENT_MIN_PERIOD` and nothing else.** There was a six-option
*Sync period* select, and the client floored its fast end to a minute anyway, so the label
contradicted the Network tab: 15s and 30s were the same setting wearing two names. It is
gone, and the client applies the topbar number as sent — one copy of "a minute", on the
server, because two copies is two places to forget. The `auto_poll` switch kept the one part
that was never a period: the off switch.

**The chain state is module level, not inside `wireSync()`.** `wireMailbox()` re-runs after
every folder swap, so a chain built in it would be one chain per swap. `wireSync()` binds the
fresh chip's button and nothing else.

**A stale listing syncs at boot, not after a period.** The header cache survives logout, so a
fresh login used to paint a six-day-old mailbox and leave it until the first tick a minute
later. `view_mailbox()` runs `sync_is_stale()` on the folder's `last_sync_ts` and, when it is
zero or at least one period old, ships `data-sync-stale="1"` on the `.sync` chip. At boot, a
page with a period and a stale chip calls `syncPoll(false)` at once (it reschedules itself);
everything else keeps the one-period cadence. A fresh chip is deliberately not flagged: an
eager sync on every navigation would be redundant, and the server throttle is only 15s.

Each tick re-reads `.sync[data-folder]` **at request time and again on response**. Capturing
it once was a live bug: a sync in flight during a folder swap dragged the user back to the old
folder. A page with no chip (reader, compose, settings) names `INBOX`, and the server syncs
INBOX alongside whatever folder was named.

A refresh is `win.location.href = win.location.href` — the current URL, so the pagination
survives. It happens only when `syncCanSwap()`: a `.sync` chip is present (so there is an open
listing at all), and no `dialog[open]`, no `.row-gone`/`.busy`/`.opening`, `kbCursor < 0`,
`pickCount() === 0`, `scrollY === 0`, page 1. The chip check is the one that saves a draft: the reader, compose and
settings have no chip, `changed` there is the Inbox's, and reloading the composer would throw
away the message being written. Otherwise `syncStale` remembers it and a later tick refreshes
even when that sync reports nothing new. There is no `reloaded` flag any more: that was the
one-refresh-per-page-load guard, and a poll loop needs to keep refreshing.

`pickCount()` is in that list for the same reason `kbCursor < 0` is: a swap throws
the selection away, and the batch about to act on it goes with it. A blocked swap
does not stall the chain — the tick sets `syncStale`, so the list reloads up to one
period after the selection clears. `syncCanSwap()` is module-private, so the js guard
is a **row in the S63 poll guard table** that selects by a dispatched click and
asserts through `win._hrefs`; calling the predicate directly would test the predicate
and not the wiring that has to reach it. That row needed a fourth slot in the table,
because state that only exists once the handlers are wired cannot be set before
`boot()` — `pickReset()` wipes it on the way in.

`pageshow` with `persisted` resets `syncBusy` and syncs now: a page restored from the
back/forward cache was frozen mid-request, and the request went with it. A 401, a 403 or a
redirect stops the chain for good and the chip reads "Signed out" — retrying would loop
against a session that no longer exists. Two hours without a `pointerdown`/`keydown` stops it,
so a forgotten window does not hold the session and its decrypted password open; the next
input starts it again.

`titleWithCount(title, n)` is pure and strips `^\(\d+\) ` first, so it is idempotent. It is
applied at start, in `setBadge()` (so the optimistic read toggle moves it too), and after
`loadMailbox()` sets the server's title. Count = the Inbox sidebar badge, which `d.counts.INBOX`
already carries.

Notifications are opt-in per browser (`localStorage['emmmail-notify']`), Inbox only, and a
running count since the tab was last focused. `tag: 'emmmail-inbox'` makes a second tab or a
second poll replace the banner instead of stacking, and `visibilitychange`/`focus` closes it
and zeroes the total. `doc.hidden || !doc.hasFocus()` is the gate: telling a user about mail in
a tab they are looking at is noise. A fixed tag plus a try/catch around the constructor covers
Android Chrome, which grants permission and then throws.

## Conversations in the list and the reader
A conversation row is an ordinary `.mail` row plus `data-thread`/`data-thread-uids`. Two
things read those:

- **`toggleRead` posts `thread=<key>`, not the anchor uid**, when the row has `data-thread`.
  The row's `.unread` means "any member unread", so its own class cannot say how many members
  flipped; the badge moves by the server's `count` (this folder's flipped members) after the
  reply, and the row unbolds optimistically with an exact rollback.
- **The box expands to every in-folder member.** The `.mail-pick` change handler reads
  `data-thread-uids` and calls `pickToggle` per member, so a bulk action carries the whole
  set. `pickUids()` still sorts, so the POST is ascending.

`view_thread()` renders one `<details class="thread-msg">` per message (newest `open`), each
carrying the same `data-uid`/`data-folder`/`data-reply*` hooks a `.card` has. That is what
lets the reader scale from one message to many:

- **`activeThreadCard()` is the reader's new seam.** `readerControl()` and `kbReaderKey()`
  target the last open `<details>` when there is one, else the single `.card`. Without it,
  `$('.actions-bar')[0]` and `doc.querySelector('.card')` always named the FIRST message, so
  a keyboard action or a confirmed Delete spun the wrong control.
- **Exactly one `#move-dialog` per page.** `view_thread()` emits it once at the top level;
  each message's action bar reuses it via `moveTo(uid, folder)`.
- **A thread message drops the reader's avatar + subject block** (`view_message_body(...,
  ['no_title' => true])`): the conversation has one subject and each summary already carries
  the sender and date, so repeating them per message is noise.
- A `toggle` listener re-runs `fitSrcdoc()`, because a collapsed `<details>` gives its iframe
  no box to measure.

## Mark-as-unread cannot reload
`handle_read()` marks read on every view, so `win.markUnreadBack()` posts and then navigates
away.

## The login page remembers three things, all in `localStorage`
`emmmail-last-email`, `emmmail-last-preset`, and `emmmail-last-custom` (a JSON object keyed by
the form's own field names: `imap_server`, `imap_port`, `imap_enc`, `smtp_server`, `smtp_port`,
`smtp_enc`). The custom fields are reached by `[name="..."]`, not by id, so `view_login()` must
keep those `name`s — a PHP-side test pins them.

**It has to be client-side.** `handle_login()` stores the servers per account and `view_login()`
reads them back, but only when `$email` is in `$_POST`, which is true only after a failed
attempt; a fresh `?page=login` has no email to look up.

**Restore overwrites, it does not fill-if-blank.** A fresh GET renders the ports and the
encryption selects as *defaults*, not blanks, so a blank test would throw the remembered value
away. Setting `$('#login-preset').val()` fires no `change`, so `.custom-servers`' display is set
from the restored value on the same pass.

## Keyboard
One document-level `kbDispatch`, gated by `kbEnabled` (a localStorage off-flag), `kbAuthed`
(`.topbar` exists), `kbEditable` (Esc always passes) and `kbModalOpen`. PHP owns one
`kb_shortcuts()` table that feeds both the `?`-sheet and the `data-kb-list` JSON, so they
cannot drift. `kb_folder_map()` renders `<body data-kb-map>`, because the reader and settings
have no sidebar for `g X`. `read_prev_next()` ranks over the whole cached folder, since
`db_list_messages` is page-limited.

**Escape hatch:** `?` opens the sheet *before* the `kbEnabled` flag is read, so a user who
clicked "Disable shortcuts" is never trapped. The sheet's link is a toggle
(set/remove `emmmail-kb`) and the avatar-menu Help item always opens it.

`kbBusy(el)` = `markBusy(el)` or the chrome bar, so keyboard navigation acknowledges its wait
like the mouse does. `g X` spins the target folder's sidebar link, matched on the **decoded**
name, because hrefs are PHP `urlencode()`d.

`x` selects the cursor's row instead of opening it. With no cursor yet it places
one first, on the first row: the shortcut nobody would try is the one that most
needs to work without a prior `j`. It toggles, and it costs **no request** — the
selection is client state, and a POST per keypress would mark nothing.

`kb_shortcuts()` drives the sheet, but nothing in PHP ties that table to the JS
switch, so a row there is satisfied by an entry that does nothing. The `x` entry has
a source guard on `case 'x': kbSelectCursor();` for that reason.

`kbGoFolder` toasts `No such folder.` and stops when the map has no entry for the role. That
is the only no-folder exit in the path, and it is why the Inbox fallback exists rather than
a guard.

Form actions carry a modifier so they work while typing and never collide with the mail keys:
`Cmd/Ctrl+S` = Save draft (composer) or Save settings, `Cmd/Ctrl+Shift+A` = attach,
`Cmd/Ctrl+Shift+X` = clear local cache. Each clicks the control a user would.

`kb_folder_map()` always resolves `i` => `INBOX`, whatever the folder list holds. It is
the one unconditional role: RFC 3501 requires that folder, `syncTitle()` already reads the
`INBOX` badge, and the poll chain names `INBOX` on every page without a sync chip. Every
other role needs a match, or the map came out empty and `g i` toasted `No such folder.` on
a page whose folder cache was merely cold — which is exactly what it did. A stale
`protected_folders` inbox mapping loses to the fallback too: it can only name a folder the
list does not have.
