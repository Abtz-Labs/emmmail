# Browser notifications, title counter, poll loop

**Status:** done
**Date:** 2026-09-29
**Reviewed:** 3 subagents (server, client JS, platform/load). Findings merged below.

## Problem

An open, idle tab never learns about new mail. `wireSync()` (index.php:7861)
runs one sync per page load or folder swap. `sync_period_seconds` only
throttles on the server. Only listing pages have a `.sync` chip (8893), so the
reader, compose and settings pages never sync.

## Decisions (agreed with the user)

- **Poll on every signed-in page.** Always sync INBOX; on a listing page, also
  sync the open folder. Period = `max(sync_period_seconds, 60)`; 0 = no poll.
  Also sync once when the tab becomes visible.
- **Title counter:** Inbox unread, `(3) Inbox — Emmmail`. Not shown at 0.
- **Notifications:** opt-in, per browser. **Count only**. Inbox only. Only when
  `document.hidden || !document.hasFocus()`. Click focuses the tab.
- **List refresh when idle only.** Otherwise mark stale and retry on a later tick.
- **Idle stop:** no user input for `POLL_IDLE_STOP_SECONDS` (2 h) -> stop the
  poll, so a forgotten tab does not keep the session (and the encrypted
  password) alive. The next input restarts it. PHP `gc_maxlifetime` (1440s
  default) then ends the session as usual.
- **Out of scope:** sound (CSP blocks `data:` audio; autoplay rules; the OS
  plays one), Web Push / service worker (needs VAPID and an IMAP watcher
  process), favicon badge.

## Design

### Server

- `sync_folder()`: `arrivals` = count of `$result['upsert']` rows with
  `unread` and `uid >= $state['uidnext']`, only when `$state !== null`,
  `!$plan['purge_all']` and old `uidnext !== null`. So:
  - true cold start and UIDVALIDITY purge -> 0;
  - the cold-window refill of an emptied folder (Inbox zero) still reports
    the genuinely new message;
  - the stray `<max+1>:*` old row is excluded (sync_reconcile drops it).
  Default `'arrivals' => 0` in `sync_folder` `$summary` and in `sync_request`
  `$out` (incl. the skipped path).
- `handle_sync()`: if `$folder !== 'INBOX'`, run `sync_request()` for INBOX too,
  on the same `$shared` connection, before `counts_sweep()`. `arrivals` comes
  from the INBOX run; `changed` stays the open folder's. New `folder` value
  from a non-listing page: `INBOX` (no open-folder sync).
- `handle_sync()` answers **401 JSON** when not signed in (today
  `require_login()` redirects, `r.json()` throws, the chip says "Offline"
  for ever).
- Period for the client: `data-period` on a signed-in-only element present on
  every page (the sidebar / topbar), not injected into `js_inline()`.
  PHP-side markup test.
- Known behaviour, accepted: a move into INBOX (restore, not-spam) gets a new
  UID; if unseen, it counts as an arrival.

### Client

- **One module-level scheduler** (`syncTimer`, `syncBusy`, `syncStale`,
  `lastInput`). `wireSync()` only binds the chip click. Each tick reads the
  current `.sync[data-folder]` (if any) at request time and checks it again
  on response. `clearTimeout` before each reschedule. Remove `reloaded` and the
  per-swap `requestIdleCallback` sync (the scheduler owns the first run).
  This also fixes today's bug: a sync in flight during a folder swap pulls the
  user back to the old folder (7909).
- **Refresh rule:** on `changed > 0` for the open folder, swap only if no
  `dialog[open]`, no `.row-gone`/`.busy`/`.opening`, `kbCursor < 0`,
  `scrollY === 0`, page 1. Else `syncStale = true`; retry on a later tick even
  when that sync says `changed: 0`. Refresh `win.location.href`, not
  `?page=folder&folder=X` (keeps pagination).
- **Title:** pure `titleWithCount(title, n)`, strips any `^\(\d+\) ` first
  (idempotent). Count = Inbox badge (`badgeFor('INBOX')`). Applied at start,
  in `setBadge`, and after `loadMailbox` sets `d.title`. No server prefix, no
  `inbox_unread` field (`d.counts.INBOX` already has it).
- **Notification:** `tag: 'emmmail-inbox'`, so a second tab or a second poll
  replaces instead of stacks. Text = running total since last focus
  ("1 new message" / "N new messages"). Reset and `close()` on focus/visible.
- **Stop polling** on 401, 403 or `r.redirected`; chip says "Signed out".
- **Idle stop:** `lastInput` updated on `pointerdown`/`keydown`; a tick with
  `now - lastInput > POLL_IDLE_STOP_SECONDS` stops; next input restarts.
- **BFCache:** on `pageshow` with `persisted`, reset `syncBusy`, clear the
  timer, sync now.
- Stub-guarded: no bare `Notification` / `localStorage` / `document.hidden` /
  `hasFocus` / `isSecureContext`.

### Settings: "This browser" card

- Outside the settings form (it posts on Save; a live switch there misleads),
  like `.cache-card`. Button acts at once, like the `emmmail-kb` toggle
  (8326-8338). Flag: `localStorage['emmmail-notify']`.
- Hidden if `Notification` is missing or `!isSecureContext` (covers iOS Safari
  without an installed app, and plain HTTP).
- `requestPermission()` called directly in the click handler (no `await`
  before it). On grant, send a test notification in `try/catch`; if it throws
  (Android Chrome: "Illegal constructor"), turn off and say "Not supported on
  this device". On `denied`: "Blocked in browser settings".

## Steps

All steps are done: `just test` is green (2512 passed, 2 smoke skips), and the user ran
step 9 in Brave on macOS and closed the plan. Firefox, Safari, the plain-HTTP card and the
2-hour idle stop were not reported on separately.
Two decisions moved off the design above while the tests were written; both are
recorded in [docs/knowledge/frontend.md](../knowledge/frontend.md).

1. [x] Harness: fake timer queue (`setTimeout`/`clearTimeout` ids + `tick()`),
   settable `doc.hidden`/`doc.title`/`hasFocus`, `win.Notification` stub
   (permission, `requestPermission()` promise, constructor records instances,
   optional throw), `win.isSecureContext`, `win.focus`, `pageshow.persisted`.
2. [x] Red PHP tests: arrivals (incremental = counted; seen = not; cold = 0;
   purge = 0; emptied folder + 1 new = 1; stray old row = 0);
   `handle_sync` INBOX side-sync + 401 via pure helpers; `data-period` markup.
3. [x] Server implementation. `sync_poll_folders()` was written and then deleted:
   the client names `INBOX` itself, so the server has no `$isListing` and no
   caller. The decision lives in `syncFolder()`.
4. [x] Red JS tests: one chain after N `wireSync()` calls; period floor 60; 0 = no
   poll; reschedule after success and failure; stop on 401/redirect; idle stop
   and restart; refresh rule (each guard) and stale retry; `titleWithCount`
   idempotent; title follows `setBadge`; notify only when hidden/unfocused +
   enabled + granted + arrivals > 0; running total and reset on focus; tag;
   BFCache restart.
5. [x] JS implementation.
6. [x] Settings card + PHP-side markup test + JS tests (hidden when unsupported,
   denied, throw on construct).
7. [x] README: features, settings, "best effort, tab must be open", desktop only
   (iOS needs an installed app; phones freeze background tabs; Safari may
   suspend tabs), Chrome slows hidden tabs to 1/min, idle stop.
   `js_inline()` help does not list settings, so it needed nothing; its `y` row
   ("Check for new mail") still drives the same chain through the chip.
8. [x] `just test` green.
9. [x] **Manual pass, in a real browser.** Run it yourself; no harness can stand
   in for it. Setup: `just start`, log in, and in Settings leave *Check for new
   mail automatically* on. Watch with the Network tab open, filtered to
   `page=sync`. Turn off macOS Focus / Do Not Disturb first: it sends every
   banner straight to Notification Center, so a working notification looks
   like a missing one (seen in Brave: `onshow` fired, nothing on screen).
   - **Polls**: on a listing, a `?page=sync` POST appears every 60s, not on
     page load. Reload the page: still one chain, not two. Click a folder 5
     times, wait 60s: still one POST per minute, and it names the folder you
     ended on.
   - **The off switch**: untick *Check for new mail automatically*, Save, and
     wait two minutes: no POST at all. Click the sync chip: exactly one, with
     `force=1`. The Inbox badge and the tab title still update from it.
   - **Title**: with unread in the Inbox, the tab reads `(3) Inbox — Emmmail`.
     Open a message, it drops to `(2)`. Back to zero unread, no prefix. It must
     never read `(3) (2) Inbox…`.
   - **No reload where it would hurt**: with the listing scrolled down, or a
     dialog open, or on page 2, new mail must **not** reload. Scroll back to the
     top and wait one period: the list updates. Type a draft in Compose and
     wait two periods: **the draft survives** and no reload happens.
   - **Signed out**: open the app in a second tab, log out there, wait one
     period in the first. The chip reads `Signed out`, the tab stops posting,
     and it stays stopped after two more periods.
   - **Offline**: stop the server (Ctrl+C on `just start`; `just stop` only
     stops a `just start background`), wait one period: the chip reads
     `Offline` in red. Start it again: the chip recovers on the next tick.
   - **Notifications**: Settings shows a *Notifications* card, below the cache
     card. Click *Enable notifications* — the browser prompt must appear **on
     that click**. Accept, and a "Notifications are on." banner appears. The
     button now reads *Turn off notifications*. Hide the tab or switch to
     another window, have someone mail you, wait up to 2 periods: one banner
     titled *New mail* reading `1 new message`. A second mail updates it in
     place to `2 new messages` — never a second banner. Focus the tab: the
     banner closes and the total resets. Open the app in a second tab with
     notifications on: still one banner, because the tag is fixed.
   - **Denied**: in the browser's site settings set notifications to *block*,
     then click *Enable notifications*: the button returns to *Enable
     notifications* and a toast reads `Blocked in browser settings`. No banner,
     ever, and no console error on any later poll.
   - **Plain HTTP**: `localhost` counts as a secure context, so the card shows
     there. Serve on all interfaces (`php -S 0.0.0.0:2526 index.php`), log in
     at `http://<LAN IP>:2526/?page=settings`: the card is gone entirely, not
     disabled.
   - **Settings layout**: Protected folders, Remote images, Check for new mail
     automatically and Undo send period have a line between each, none above
     the first. Every description wraps at the same width; controls line up on
     the right. With a trusted sender, *Trusted senders (N)* is a pill button
     left of the Remote images switch and opens the dialog; with none, only the
     switch. Clicking the *Undo send period* label focuses its select. Check it
     in dark mode and at phone width too.
   - **Idle stop**: leave the tab untouched for 2 hours with the Network tab
     open: posting stops. Click once: it starts again within one period.
   - **Cross-browser**: repeat *Notifications* in Firefox and Safari. Safari
     needs *System Settings → Notifications* for the site allowed first, and
     Safari may suspend the tab, so the poll can lag behind — that is the
     documented limitation, not a bug.

## Scope change: the custom sync period is gone

The design had the poll period read from `sync_period_seconds`, with a floor
because the stored value was a *server* throttle. That produced a select
offering 15s and 30s which the browser refused to honour — the label
contradicted the Network tab, and 15/30/60 were one setting wearing three
names. Removed:

- The select, the note under it, its read in `handle_sync()`, its write in
  `handle_settings()`, and `sync_period_for_client()` (its only job was the
  floor). `.settings-row-2` went with it; Undo send is a full-width group now.
- The client no longer floors anything: it applies the topbar number as sent, so
  `SYNC_CLIENT_MIN_PERIOD` (60) has exactly one home. A test asserts the string
  `SYNC_MIN_PERIOD` appears nowhere in the JS.
- **Kept**, because it was never a period: an on/off. `auto_poll` (default on)
  ships as a switch, *Check for new mail automatically*, next to Remote images.
  Off means `data-period="0"`, and the chip click still syncs by hand.
- **Kept**: `SYNC_MIN_INTERVAL_SECONDS` (15) as `handle_sync()`'s `min_interval`.
  That is a throttle on the endpoint, and it is what stops a burst of chip
  clicks from becoming a burst of IMAP work.
- Existing `sync_period_seconds` rows are left in the `settings` table. No code
  reads them, a test proves a stale one does not resurrect a poll, and deleting
  per-user rows is destructive. The key is free to come back when a scheduled
  background process makes a period meaningful.

## One guard the design missed

`changed` on the reader, compose and settings pages is the *Inbox's* count,
because the client names INBOX there. So the refresh rule as written above would
have reloaded the composer, and with it the draft being written. `syncPoll()`
now returns before the refresh unless a `.sync` chip is present, and S63 pins it
for the reader, compose and settings.
