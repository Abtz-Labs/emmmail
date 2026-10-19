# Bulk actions: mark read/unread, move, delete

**Status:** pending
**Date:** 2026-10-02
**Reviewed:** 2026-10-02 — five subagent reviews (IMAP, PHP/security, frontend, testing,
process). Every blocker below is a finding from that pass; the reasoning errors in *Why*
were mine.

## Problem

1. Every mutation is single-UID. Triage means one tap per row. `handle_mark_read(bool $read)`
   (5399), `handle_move()` (5193) and `handle_delete()` (5156) each read
   `$uid = (int)($_POST['uid'] ?? 0)`, and `MailImap` agrees — `markAsReadByUid`,
   `deleteEmailByUid`, `moveToFolder` all take one UID. No checkbox, no select-all, no
   selection state exists in the list at all.
2. The existing per-message machinery has three properties that make a naive batch
   *dangerous*, not merely slow. They are the whole reason this plan is not "wrap it in a
   loop", and each is stated below with the evidence.
3. `docs/plans/2026-09-29-keyboard-first-ux.md:60` deferred `x` and bulk actions on purpose,
   pending "a plan of their own".

## Goal

- Select rows in the current listing, then mark read, mark unread, move, or delete, once.
- Every bulk action runs the *same* gate and cache write as its single-row twin.
- No new route. The `.mail` grid is untouched.

---

## Why these choices and not the obvious ones

**No new route — extend the three handlers.** `folder_move_check()` (9743) takes only
`($from, $to, $folders)`, all per-*request*: `$from` is the one `selectFolder()`, `$to` is
one destination, `$folders` is memoised at 3182. Nothing in it varies per UID, so one call
covers the batch and re-checking per message buys nothing. A separate `?page=bulk` route
would duplicate the trash-vs-permanent branch and the gate, and the two copies would drift.

**The wire format is a comma string.** Not because `$.param` cannot send arrays — it can;
`URLSearchParams.set` stringifies `[1,2,3]` to `"1,2,3"` (I had this wrong in the first
draft). Because the JS builds the string by walking the DOM-ordered row list, and PHP must
receive it in that order.

**One IMAP command per batch, addressed by UID with `CP_UID`, then one expunge.**
`imap_mail_move()` takes an `$options` argument and PHP passes it straight through
(`php_imap.c:1382-1390` rejects anything but `CP_UID`). So a batch is:

```
UID COPY 1001,1002,1003 Archive
UID STORE 1001,1002,1003 +Flags \Deleted
EXPUNGE
```

Three round trips, no sequence numbers anywhere. **My first draft resolved every UID to a
msgno, then moved them one at a time, justified by "nothing is removed until the last
message has moved". That reason is false** — see the c-client fact below. Resolving msgnos
is also not free: on a freshly SELECTed connection every `private.uid` is 0, so each
`imap_msgno` issues a `UID FETCH` round trip (`imap4r1.c:1922-1946`). N of them, before the
first COPY.

**c-client 2007f has no MOVE support.** No `IMAP_CAPABILITY_MOVE` test, no `UID MOVE`
string anywhere in the source. `imap_mail_move` is `UID COPY` + `UID STORE +Flags \Deleted`
and **never issues the EXPUNGE** — the caller does. So the RFC 6851 §3.3 renumbering rule
my draft leaned on never fires here, while `docs/knowledge/imap.md:35` ("Deletes go by
**UID**, never sequence number") still applies. A future c-client with real MOVE would
invalidate a sequence-number batch; a UID batch is correct under both.

**Batch methods return `int`, not `bool`.** `php_imap.c` discards the return of
`mail_setflag_full` and `RETURN_TRUE`s unconditionally — so `deleteEmailByUid` can never
return false, `$ok` at 3818 is always true, and `markAsReadByUid` is the same. A `bool`
from a batch of N is a lie one level down: the handler cannot tell the client how many
messages it actually touched, so the client cannot roll back honestly. Each method drains
`imap_errors()` around the call and returns the number of messages that went through. Also
note `ST_SILENT` is **not** available — `php_imap.c:2632-2635` rejects it — so a
`UID STORE` over 200 UIDs returns 200 untagged `FETCH` lines to parse. One round trip, but
not a small one.

**The checkbox is a child of `.mail-avatar`.** The row is a 3-column grid
`[avatar][main][actions]` (6198, and the 620px override at 6611). `.mail-avatar` is
already `position:relative; z-index:1` (6208), so an absolutely centred child costs zero
columns, no responsive `left` value, and no media query — 40px and 36px avatars both work.

**Selection is per page.** Cross-page selection would make one bulk delete span folders,
and the trash-vs-permanent branch assumes a single folder. It also removes the mixed-set
case from the move gate entirely.

**No undo for bulk delete.** `handle_delete` has none either; adding one only here would
make single and bulk disagree. Follow-up, not this plan.

---

## Plan

TDD order is **red first, per step**. Steps 0–2 are test and harness work on purpose: the
first two defects below are invisible until the tests can express them.

### Step 0 — Red tests and the js harness (tests/js.php ~105–540, ~2810–2880)

No application code yet. Three harness changes and their guard tests, because the JS cannot
be tested at all until they land:

- `makeEl`: a fresh checkbox reads `checked === false`, not `undefined`; `click()` on
  `type="checkbox"` flips it and fires `change`; a `<button>`/`<a>` `click()` fires **no**
  `change` (this guards `kbCursorOpen` at 8348 and the composer submit at 8722). The
  `INPUT` gate is inert for all 662 existing scenarios — `grep '\.click()' tests/js.php`
  finds one hit, a comment.
- **Extend `buildList()` (498–542) to mirror `index.php:9312-9313`**: the avatar span,
  `input.mail-pick[data-pick-uid]`, `span.mail-actions`, the head select-all, and
  `div.bulk-bar.hidden`. Today it builds `[button][a.mail-main]` only. Without this a
  `TypeError` is reported as one `<harness crashed mid-run` line and 662 tests vanish.
- **Add a permanent PHP assertion that no report entry is named `<harness crashed mid-run>`**
  (the loop at 3418–3427). Nothing asserts on that name today. This is the assertion that
  would have caught the original `querySelector` incident, and it is what stops one
  `makeEl` change becoming many failures.

Red test: the guard tests themselves, plus `buildList` extended and no existing scenario
regressing.

`removeChild()` not pruning `_all` is **not** a gap — it is documented at
`docs/knowledge/testing.md:39-41` and already worked around at 9 sites. Assert removals on
`parentNode`, as `tests/js.php:1206` does.

### Step 1 — `post_uids()`, red first (index.php ~2279, ~5156–5420)

Red test first, and it must **not** be a bare call to a function that does not exist yet:
`tests/run.php:20` requires `unit` first, so an uncaught `Error` kills the process and js,
imap and smoke never start — 888 passing tests become invisible. Gate on `function_exists`
and `failTest`, the precedent at `tests/imap.php:59-63`.

`post_uids()` reads `uid` (the existing scalar) or `uids` (`"1001,1002,1003"`) and returns
de-duplicated positive ints, in the order sent. Three holes, all of which I missed first:

- **`is_string()` before `explode()`.** `uids[]=1001` makes `$_POST['uids']` an array, and
  `explode(',', $array)` is a fatal `TypeError` in PHP 8 — a 500 with no flash. A `(string)`
  cast is not the fix: it emits "Array to string conversion" and yields `"1"`, silently
  acting on UID 1. The same guard fixes `uid`, where `(int)` on an array also yields 1.
- **Bound the raw string before splitting.** `explode(',', str_repeat(',', 4MB))` allocates
  **132 MB** — over the 128M default `memory_limit`, from one request. Today the path is
  O(1). Reject a raw longer than `12 * cap` (200 UIDs × 7 digits + 199 commas = 1599 bytes)
  before splitting.
- **`trim()` + `ctype_digit()` per element, not `(int)`.** `(int)'1e3'` is `1000` — a valid,
  different, existing message. `(int)` saturates rather than wraps, so overflow is not a
  risk; scientific notation is. This deliberately diverges from the single-row `(int)` path,
  which is a second reason to route both through the normalizer.

The cap is **200**, because that is the per-page ceiling: `index.php:5608` clamps
`max(5, min(200, $perPage))` and every listing path honours it. It is a real bound, not
error handling for an impossible scenario — and the risk is the opposite of impossible, so
the bar must say when it truncated (`docs/knowledge/testing.md` on silent truncation).

Then, per handler: read `$uids`, make **one** batch IMAP call, then loop the per-UID cache
write. `folder_move_check()` runs **once**. `respond_mutation()` and `json_out()` are called
**once, after the loop** — both exit, so a per-UID loop would answer after the first UID and
silently drop the rest.

Delete the now-dead singulars: `markAsReadByUid`, `markAsUnreadByUid` and `moveToTrash` lose
their only production callers to this change. `deleteEmailByUid` survives (undo send,
flush undo). `moveToFolder` survives as the N=1 case.

Return the affected count so the client can compare: `json_out(['success' => true, 'count' =>
$applied])`, and `respond_mutation()` gains `count`. Today `handle_mark_read` returns
`success: true` even when `$uid === 0` and the folder was never opened; for a batch that
becomes "200 UIDs, none of which exist".

On a failed batch, call `cache_invalidate_folder()` **without** deleting rows. A partial
success is unreportable with this API, and `sync_reconcile()` has no path that re-adds a
server-present UID missing from the cache — `db_delete_messages()` has no undo. Deleting
rows the server kept hides live mail permanently.

Two existing assertions break and Step 1 rewrites both: `tests/unit.php:950` (the literal
`markAsReadByUid`/`markAsUnreadByUid` ternary) and `tests/unit.php:1059`
(`cache_apply_move(current_user(), $folder, $uid, $dest)`). `unit.php:1057` survives only
if `folder_move_check($folder, $dest, $imap->getFolders())` stays verbatim, outside the loop.

Also add the CSRF-idiom source guard (none exists) and
`imap_folder_name_is_safe($folder)` on the three handlers — pre-existing, not introduced
here, but the top of all three is being rewritten and `$folder` reaches `imap_reopen()`
unvalidated today.

### Step 2 — IMAP batch methods, red first (index.php ~3768–3856)

Red test first, and it is a **wire-level** test in `tests/imap.php`, not a source grep.
`tests/imap.php` has zero mutation coverage today, and a `substr_count` guard cannot count
a runtime expunge. `_imap_stub.php` logs every command (183) and falls through to `OK` for
unknown commands (290), and `tests/imap.php:26-38` already ships `cmds()` and `billable()`
for exactly this. **Assert the command trace, never the literal word `EXPUNGE`** — the stub
advertises no `MOVE` capability (167), so c-client may `CLOSE` instead. Filter
`/^(EXPUNGE|CLOSE)/i`.

```
moveToFolder(1001)         -> 1 move, 1 wipe
moveManyToFolder(3 uids)   -> 1 move, 1 wipe      (not 3 of each)
markManyAsRead(200 uids)   -> 1 UID STORE, 0 wipes
deleteManyByUid(3 uids)    -> 1 UID STORE, 1 wipe
moveManyToTrash, no Trash  -> falls back to delete, still reports a count
```

- `moveManyToFolder(array $uids, string $dest): int` — `imap_mail_move($this->conn,
  implode(',', $uids), $dest, CP_UID)`, then one `imap_expunge()`. **One** occurrence of
  `imap_mail_move($this->conn` in the file, so the guard at `tests/unit.php:959` survives —
  which is why `moveToFolder` delegates to it rather than keeping its own call:
  `moveToFolder(int $uid, string $dest): bool` keeps the `imap_msgno` existence check
  (`uid === 0` returning false is what makes the single path report honestly — `UID COPY`
  with no match is answered `OK` by every conforming server) and then calls
  `moveManyToFolder([$uid], $dest) > 0`. No third helper, and the guard holds at exactly 1.
- `markManyAsRead(array $uids, bool $read): int` and `deleteManyByUid(array $uids): int` —
  one comma sequence each. Both drain `imap_errors()` around the call, because their PHP
  wrappers always return true.
- `moveManyToTrash(array $uids): int` — `moveManyToFolder`, falling back to
  `deleteManyByUid` when `findTrashFolder()` returns null, mirroring `moveToTrash`.

A batch is **all-or-nothing at the IMAP layer.** A comma sequence naming one stale UID is
rejected as a unit, so one racing sync means zero rows change server-side while the client
has already painted them. The client compares `count` and rolls back on a shortfall, which
turns that lie into a visible no-op.

**The round-trip budget is 3, not the 2N the first draft claimed:** `imap_mail_move` is
*two* commands (COPY then STORE), and `imap_msgno` adds one more per call.

### Step 3 — Markup (index.php ~9250–9356)

`view_mailbox()` renders standalone as the `?page=folder&ajax=1` payload, so all three pieces
go inside it:

- `input.mail-pick[type="checkbox"][data-pick-uid]` as the last child of `.mail-avatar`
  (9313), after the letter, with `aria-label="Select message from <from>"`. The class
  attribute `class="mail-avatar av-"` is untouched, so `tests/unit.php:2736` still passes.
- A select-all checkbox in `.mailbox-head` (9252) with `aria-label="Select all messages"`.
- `.bulk-bar.hidden` after `.mailbox-head`, carrying a `role="status"` count and four
  buttons. `openConfirm()` (8764) already takes arbitrary `fields`, so the Delete button
  passes `{uids, folder, ajax:'1'}` with no new dialog.

Buttons carry `data-bulk-action="read|unread|move|delete"`, **never inline `onclick`** —
the stub has no attribute-handler wiring, so an inline handler is invisible to the js suite
and Step 0's scenarios would pass vacuously.

`win.bulkMoveTo(folder)` opens `#move-dialog` with `_uids`; the confirm guard becomes
`!mdlg._uid && !mdlg._uids`, because today `if (!dest || !mdlg._uid)` (7967) closes silently
on a bulk move. `openConfirm` nulls `_run`, and the dialog lives in `render_page()` (5823),
so it survives a mailbox swap — the closure re-checks `pickCount()` before acting on UIDs
that may no longer be selected.

### Step 4 — CSS (index.php ~6196, 6159, 6633)

- `.mail-pick` — `position:absolute; inset:0; margin:auto`, 24px (WCAG 2.5.8 minimum;
  fits the 36px phone avatar), `z-index:1` inside the avatar's stacking context, a
  `--surface` ring to separate it from the letter, and a
  `:focus-visible { outline:2px solid var(--accent) }` — `opacity:0` hides the default ring,
  and `.mail:focus-within` only reveals the control.
- Reveal with **`opacity`, never `display`/`visibility`** (as `.mail-actions` does at 6219),
  so the input stays in the tab order: on `:hover`, `:focus-within`, `.mail.picked`, and
  `@media (hover:none) { .mail-pick { opacity:1 } }` mirroring 6222. Without the last one
  the feature is invisible on touch, which is the stated motivation.
- The "bar is visible" rule is **CSS-only and never queried from JS**:
  `.mailbox:has(.bulk-bar:not(.hidden)) .mail-pick`. `:has()` is already in use
  (`docs/knowledge/css-ui.md:67`); `:not()` and `~` are not supported by the stub and must
  not appear in a JS selector.
- `.mail.opening .mail-pick { opacity:0 }` — `.mail.opening .mail-avatar::before` (6236) is
  the opaque spinner cap, and a checkbox on top of it hides the spinner.
- `.mail-picked` **must beat** `.mail.unread:hover` (0,3,0 at 6203) and
  `.mail.unread:active` (0,3,0 at 6245), which outrank a lone `.mail-picked` (0,2,0)
  *regardless of source order*. Declare
  `.mail.picked, .mail.unread.picked, .mail.unread.picked:hover { background:var(--accent-soft); }`
  after 6245. A selected unread row under the pointer is the state a user looks at longest,
  and `assert_contains('.mail-picked', css_inline())` is green for this bug — so the guard
  must pin the full declaration, as `tests/unit.php:2821` does.
- `.bulk-bar` gets `margin-left:0` and its own flex declaration. `.toolbar` (6159) carries
  `margin-left:auto`, and `.mailbox` is a column flex (6154), so an auto cross-axis margin
  absorbs the free space and the bar shrink-wraps right instead of spanning. `.hidden` (6633)
  is the last top-level rule, so `.bulk-bar.hidden { display:none }` would lose the tie at
  (0,1,0) — the class order matters, and that is worth a comment.
- Buttons are icon + `.btn-label` + `data-tip` + `aria-label`, never `title`. At 620px,
  `.btn-label { display:none }` (6623) collapses them to icons — the documented trap at
  `docs/knowledge/css-ui.md:34-37`. Delete is `.btn-danger-soft`.

No new tokens — everything uses `--surface`, `--text`, `--accent` and the existing borders —
so the three dark blocks (5925, 5959, 5987) are untouched. And `tests/unit.php:1022` only
sees a rule that is **entirely on one line at column 0**; it cannot catch a duplicate
multi-line rule, so the new declarations are kept single-line where a guard should see them.

### Step 5 — JS (index.php ~6821, ~6905, ~7918–7990, ~8034, ~8537)

`var pickSet = {}` goes **textually above** the boot call `wireMailbox()` at 8273.
`pickReset()` hoists; `var` does not. Below it, the first call runs `pickReset()` against
`undefined`. `tests/unit.php:1674-1676` already pins module-level state for this reason.

`pickReset()` is the first line of `wireMailbox()`, which `loadMailbox()` calls after every
swap (7878) — folder switch, page change and `popstate` (8265) all route through it, so no
separate popstate reset is needed. It owns all three: `pickSet = {}`, remove `.picked` from
each row, uncheck select-all, `pickBar()`.

Three logic errors the first draft would have shipped:

- **`bulkAction` takes an explicit direction.** `win.rowAction`'s flag mode computes
  `nowUnread = !row.classList.contains('unread')` **per row** — unambiguous for one row,
  meaningless for N. With 3 unread and 2 read rows selected, a per-row toggle on *Mark read*
  marks the 2 read rows **unread**, painted instantly. Signature
  `win.bulkAction(action, unread)`; the patch is `toggle('unread', unread)`.
- **Bump the badge per row, and sum the returned deltas.** `bumpBadge` (6884) returns the
  change actually applied, so `bumpBadge(folder, dir, row.classList.contains('unread') ?
  null : 'total')` per row and one undo of the sum is exact. A single call cannot be: with
  an unseen badge of 12 and 5 rows deleted of which 3 were unread, the server says 9 and one
  `bumpBadge(-5)` paints 7. It also corrupts `doc.title` via `setBadge` → `syncTitle`. Every
  single-call variant is wrong, and the Drafts-style total case makes it accidentally right,
  so it hides in manual testing.
- **The rollback captures nodes, never re-queries.** Build
  `patched = [{row, wasUnread, delta}]` up front and undo from that array. A closure that
  re-runs `doc.querySelectorAll` finds nothing in a browser after a confirmed remove — the
  undo silently no-ops — and finds *ghost* rows in the stub, so the test goes green while
  the feature is broken.

Range resolution uses `doc.querySelectorAll('.mail[data-uid]')`, flat, because the stub's
`matches()` has no descendant combinator — verified: `.mail-list .mail` parses the trailing
space into the class name `mail-list ` and matches nothing. `.mail` alone is unambiguous
(`index.php:9312` is the only bare `mail` class token, and `mail-list`/`mailbox` fail the
whole-token comparison), but `[data-uid]` is better: `kbRows()` already uses it, and it
cannot resolve across a listing and a future thread.

`kbEditable()` (8303-8304) returns true for `tag === 'input'` **unconditionally**. The row
controls today are `<button>` and `<a>`, so this is the first focusable input on a row, and
one mouse click would kill `j`/`k`/`x`/`#`/`e` until the user clicks elsewhere. The change
handler calls `this.blur()` — simplest, and the pattern pickers use.

`syncCanSwap()` (8034) gains `if (pickCount() > 0) return false;`. A blocked swap does not
stall: the tick sets `syncStale` (8126-8129) and a later tick swaps even if that sync reports
nothing new. The consequence to accept is that the page reloads up to
`SYNC_CLIENT_MIN_PERIOD` after the selection clears — the same contract `.row-gone` and
`.busy` already have.

Keyboard: `x` in `kbListKey` (8537) **places the cursor when `kbCursor < 0`**, because
`kbRowFields()` returns null without one and `x` would be a no-op on a fresh page. Escape
clears the selection before it clears the cursor. `Ctrl/Cmd+Shift+X` (8710) is a separate
listener, gated on meta/ctrl *and* `.folder-map-json`, so there is no collision.

### Step 6 — Tests

Every guard gets a `//` above it naming the bug that caused it — the convention at
`tests/unit.php:936-943`, `:957`, `:1056`, `:1100-1102`. A guard without its bug is a guard
nobody dares delete.

**unit.php** — `post_uids()`: scalar, comma list, `uids[]` array, a non-digit element,
`1e3`, empty, the byte bound, the cap, and order preservation. `ReflectionMethod` return
types are `int`, not `bool`, for the four new methods (gated on `method_exists`). Rewrites
of `:950` and `:1059`. New: CSRF idiom on the three handlers; `imap_folder_name_is_safe` on
the three; **two body-scoped** guards for `handle_delete`'s branches (the technique at
`:2163-2169`), because a single "calls the `*Many` variant" guard passes if the Trash and
non-Trash calls are swapped — and that swap destroys mail. `substr_count('imap_mail_move($this->conn')
=== 1` still holds, and the `//` notes its blind spot: it counts the token, not the mover.
Full-declaration CSS guards for `.mail.picked` and `.mail.opening .mail-pick`, plus a
specificity guard in the `:2813-2815` style. `view_mailbox()` renders N `.mail-pick`, one
select-all, one `.bulk-bar`. `'x'` added to the `$needKey` array at `:3695`, **plus** a
source guard for `case 'x'` in the JS — the table alone is satisfied by an entry that does
nothing, because nothing ties `kb_shortcuts()` to `kbListKey`.

**js.php** — selection: a checkbox toggles `.picked`; the bar appears only with a selection;
select-all picks every row and clears; a real `shiftKey` click picks the range. Bulk: one
request whose body is `uids=1001%2C1002%2C1003` — form-encoded, and built from a fixture
whose UIDs are **deliberately not ascending** (rows 1003, 1001, 1002), because
`Object.keys(pickSet)` enumerates integer-like keys in ascending numeric order, which is not
the display order of a newest-first listing, and an ascending fixture would pass either way.
No reload. `ok:false` and `count < picked` both roll back every row and both counters. The
poll guard is a **row in the existing `guards` table** (2810–2835), selecting by real
dispatched clicks and asserting through `win._hrefs` — `syncCanSwap` is module-private and
cannot be called. Keyboard: `doc.dispatch('keydown', {key:'j'})` then `{key:'x'}` toggles
`.picked` with `calls.length === 0`.

**Two selector traps for the new JS:** unquoted attribute selectors match everything
(`[type=checkbox]` fails the regex at `tests/js.php:127`, so the predicate goes vacuously
true and returns every input), and `el.type` does not exist on the stub — gate on the tag
name or `getAttribute('type')`. Both new the same class of silent pass as the bare-global
guard.

---

## Verification

- `php tests/run.php` green: **2772 passed, 0 failed**, 2 smoke skips (unit 1805,
  js 729, imap 238). Baseline was 2558. `tests/imap.php` may flake once on stub
  startup — re-run before believing it.
- `php -l` clean on `index.php`, `tests/unit.php`, `tests/js.php`, `tests/imap.php`.
- No report entry named `<harness crashed mid-run>`; scenario count does not drop.
- `tests/imap.php` command traces: 3-UID move = 1 COPY + 1 STORE + 1 wipe; 200-UID
  mark-read = 1 `UID STORE`.
- Browser (dev server, Chromium, desktop and 390px):
  - select-all → bar appears with the count; mark read flips only the unread rows;
  - delete → Trash, and from Trash, permanent; **a forced server error un-hides every row
    and restores both the count and the sidebar badge**;
  - a selected unread row **under the pointer** shows the picked tint, not the hover tint;
  - keyboard focus is visible on a checkbox, and `j`/`x` still work right after clicking one;
  - a poll tick with a live selection does not swap the list, and the swap lands once the
    selection clears;
  - `.bulk-bar` computed `background-color` is solid in light and dark;
  - a nested-`Deleted Items` account: bulk delete **records what actually happens** (see
    Notes — `docs/knowledge/imap.md:136` pins that it is not recognised as Trash, so the
    no-Trash fallback hard-deletes).

## Outcome

| Step | Change | Status |
|---|---|---|
| 0 | js harness: checkbox `click()`/`change`, `buildList` extended, crash assertion | done |
| 1 | `post_uids()` with three holes closed; handlers take a UID list; count in the response | done |
| 2 | `moveManyToFolder` / `markManyAsRead` / `deleteManyByUid` / `moveManyToTrash`, one command each | done |
| 3 | `.mail-pick`, select-all, `.bulk-bar`, `data-bulk-action` | done |
| 4 | `.mail-pick`, `.mail-picked`, `.bulk-bar` CSS with the specificity and touch fixes | done |
| 5 | selection state, `win.bulkAction`, dialog seams, `syncCanSwap`, `x` | done |
| 6 | unit, js and wire-level imap tests, every guard with its `//` | done |
| 7 | README features, `docs/knowledge/imap.md` + `frontend.md`, `docs/memory.md` | done |

Steps 0-7 are code-complete. The **browser pass in `## Verification` has not been
run**, so this plan is not verified: the cosmetic and touch checks there cannot be
proved by any harness here.

## Notes

- **Nested `INBOX.Deleted Items` is not recognised as Trash** (`docs/knowledge/imap.md:136`).
  So `findTrashFolder()` returns null and `moveToTrash` falls back to a hard delete — today
  that is one message, and after this change it is up to 200, with no undo, behind a dialog
  that would read "Delete this message?". The confirm copy must carry the count and say
  "permanently" whenever the batch takes the `isTrashFolder` branch or the no-Trash
  fallback. The detection bug is a separate plan; do not fix it here.
  **Done:** the copy reads the folder list, so `delete_is_permanent()` warns on both
  branches. Detection is still unfixed.
- **200 cache writes per request** is the new cost the IMAP budget does not cover:
  `cache_mark_read` is one prepared UPDATE plus, when the flag flips, a counter bump.
  `docs/knowledge/imap.md:30` sets the house discipline. Measure a 200-row mark read and
  write the number down; hoist the two `cache_invalidate_folder()` calls out of the loop.
- `docs/knowledge/testing.md:5-6` claims `php tests/run.php unit imap` runs a subset, but
  `tests/run.php:20` ignores `$argv`. Fix the sentence, do not add argv filtering — that
  changes the entry point and is not this plan's business.
- Cut line if the plan overruns: **shift-click range**. It is not in the Goal and was never
  in the deferred scope (`keyboard-first-ux.md:59` deferred "`x` *and* bulk actions"
  together, so `x` stays). Cutting it removes `pickRange`, `pickLastUid`, one js assertion
  and one Verification line — name all four or the cut is a judgement call at 2am.
- The README route list already omits `undoSend`, `flushUndo` and `revokeSender`. Leave
  them; but do not present that list as verified by this plan.
