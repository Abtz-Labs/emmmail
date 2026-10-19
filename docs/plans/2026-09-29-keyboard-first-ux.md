# Keyboard-first UX: Gmail × Superhuman

**Status:** done (Steps 1-6 done, TDD green: 2159 passed / 0 failed; Step 7 is a
manual browser pass, deferred — it needs a real Safari/Firefox/mobile keyboard,
which the test harness cannot stand in for)
**Date:** 2026-09-29
**Review:** reviewed by three reviewers (codebase fact-check, keyboard-UX,
JS/test feasibility). Amendments from all three are folded into the steps
below; each is marked `[R1]` (codebase), `[R2]` (UX), `[R3]` (JS/tests).

## Problem

Emmmail is fully usable with a mouse, but most of the flow needs one:

- The message list is a flat set of links. There is no selection cursor, so a
  user cannot move down the list and act on messages without the pointer.
- Every row action (read toggle, move, delete, spam) is an icon button on the
  row. On a touch device or with a keyboard, the pointer is the only way to
  reach them.
- The reader has no previous/next movement. Moving between messages means Back
  to the list, then a click.
- Compose can only be sent by clicking Send.
- There is no way to reach the search field or the folders without the pointer.

Gmail solves this with a keyboard-shortcut layer (`j`/`k`, `c`, `e`, `#`, `r`,
`g i`, `/`, `?`). Superhuman goes further: every list action is reachable from
a cursor, and a shortcut sheet is one keystroke away. Emmmail should offer the
same: act on any message, move between messages and folders, search, compose
and send — all without the mouse.

## Goal

A keyboard layer on top of the existing UI, Gmail-flavoured and
Superhuman-feeling:

- **List cursor.** `j`/`k` move a visible selection up and down the message
  list, and rove real focus to the row's link for screen readers. `Enter`/`o`
  opens the selected row. `Esc` clears the selection.
- **Row actions on the cursor, and on the open message.** `e` archive,
  `#` delete, `!` spam, `r`/`a`/`f` reply / reply-all / forward, `s` toggle
  read, `v` move to a folder. After an action that removes the row, the cursor
  advances to the row that takes its place — you never stop moving. The same
  keys work from the reader, against the open message.
- **Navigation.** `g i/t/d/s/a/r` folder jumps; `u` back; `c` compose; `/`
  focus search; `j`/`k` previous/next message in the reader.
- **Compose.** `Cmd`/`Ctrl`+`Enter` sends.
- **Shortcut sheet.** `?` opens a modal listing every shortcut, rendered from
  the same table that drives the shortcuts, so the two cannot drift.
- **Escape hatches are real.** `Esc` always works — it blurs a field, closes
  the autocomplete dropdown, clears the cursor and a pending `g`, and returns
  from the reader to the list. A one-line "Disable shortcuts" toggle inside the
  `?` sheet writes a `localStorage` flag for anyone who wants the keys back.
- Every shortcut keeps the existing conventions: no `getElementById`, no new
  route, no per-page inline `<script>`, no dead code. Shortcuts are disabled
  while typing in a field (except `Esc`), while a `<dialog>` is open, and
  modified keys (`meta`/`ctrl`/`alt`) are never hijacked, except the
  composer's own `Cmd`+`Enter`.

Out of scope for this plan, deliberately (a follow-up plan of its own):
multi-select with `x` and bulk actions; a "Keyboard shortcuts" settings page
(a `localStorage` flag covers the off-switch); auto-pagination when `j` hits
the last row (PgDn/PgUp already scrolls; the cursor clamps at the ends).

## Plan

### Step 1: the dispatcher and the single shortcut table

One global `keydown` listener bound once in the boot scope of `js_inline()`
(index.php:6290), outside `wireMailbox()` — a `document` listener survives the
in-place `.mailbox` swaps that replace the section node only (index.php:7434)
`[R1]` `[R3]`.

- `kbEnabled()` — false when `localStorage['emmmail-kb'] === 'off'` (the sheet
  toggle, Step 5). Read once at boot.
- `kbAuthed()` — `!!doc.querySelector('.topbar')`. The login page (no topbar,
  index.php:5481) must not run `c`/`u`/`?`/`g` `[R2]`.
- `kbEditable(ev)` — true when the target is an `INPUT`/`TEXTAREA`/`SELECT` or
  `contenteditable`, or when `ev.metaKey || ev.ctrlKey || ev.altKey`. **`Esc`
  passes this guard** `[R2]`: it is the one key that must work while a field is
  focused (blur the field, close the autocomplete dropdown, clear the cursor,
  reset a pending `g`, and on the reader return to the list). All other
  editable-target and modified keys return immediately, so typing in
  To/Subject/body/search and browser and app shortcuts (Cmd+B/I/U, Cmd+Z) are
  never swallowed.
- `kbModalOpen()` — `!!doc.querySelector('dialog[open]')`; when a modal is
  open the dispatcher returns early and the native dialog keys own the
  keyboard (Escape closes, Enter on the focused confirm).
- `kbDispatch(ev)` — the single handler, a switch over `ev.key` gated by the
  above. Only `j`/`k` honour `ev.repeat`.
- `kbPrefix` — the `g` two-key prefix. Reset on `Esc`, on window `blur`, on a
  dialog opening, and after ~1.5s (timer compressed in tests). A non-letter key
  while pending cancels the prefix and falls through: `g` then `/` focuses
  search, `g` then `?` opens the sheet — no "No such folder" toast for
  punctuation `[R2]`.
- **The table is one source, PHP-owned** `[R3]`: `kb_shortcuts(): array`
  returns `[['key' => 'j', 'label' => 'Next message'], ...]` in
  `render_page()`'s scope. `view_kb_help_dialog()` renders the sheet from it
  (Step 5) and `render_page()` JSON-encodes the same array into
  `#kb-help-dialog[data-kb-list]`; JS reads it and binds a `run` function per
  key. No second copy to drift, unlike the md-help tables (memory.md:331).

### Step 2: list cursor (js_inline() + CSS)

- `kbRows()` — `doc.querySelectorAll('.mail[data-uid]')` then filter
  `!r.classList.contains('row-gone')` in JS. **Do not use `:not(.row-gone)` in
  the selector**: the js harness `matches()` (tests/js.php:118) cannot parse
  `:not(...)` and would return an empty list `[R1]` `[R3]`.
- `kbCursorSet(i)` / `kbCursorMove(delta)` / `kbCursorClear()` /
  `kbCursorOpen()`:
  - `set`/`move` move the `.cursor` class between rows, rove real focus to the
    row's `.mail-main` link (guarded `if (link.focus) link.focus()`) so a
    screen reader announces the message for free `[R2]`, and call
    `row.scrollIntoView({ block: 'nearest' })` when present (stub-guarded).
    Only the listing (`.mail-list` present) has a cursor.
  - `open` calls `link.click()` — a real browser fires a synthetic `button:0`
    click and the anchor navigates, so the existing ack handler
    (`wireMailbox`'s `$('.mail-list').on('click', 'a.mail-main', ...)`,
    index.php:7495) runs unchanged. The stub has no `click()` yet; the harness
    adds a default one (Step 6) `[R3]`.
  - `clear` runs on `Esc`, on `pageshow`, and on a folder switch. A pagination
    swap **restores** the cursor at the same index instead `[R2]` `[R3]`:
    `loadMailbox(href, { cursor: idx })` carries the index and re-sets it after
    the swap; a folder switch passes none and clears.
- CSS `.mail.cursor`: `box-shadow: inset 3px 0 0 var(--accent); background:
  var(--surface-3)` — shared tokens only, so no dark-theme clone (the three
  theme blocks all define them, index.php:5619/5625/5637) `[R1]`. The row has
  no native focus, so the ring is separate from `:focus-visible`.

### Step 3: row actions — list cursor and reader

Each action reads `uid` + `folder` off the cursor row and reuses an existing
global, never a new endpoint:

- `s` → `win.toggleRead(uid, folder)` (flag mode, optimistic).
- `#` → `win.confirmDelete(uid, folder, 'Delete this message?')`.
- `!` → `win.rowAction(uid, folder, '?page=spam', 'remove')`.
- `r`/`a`/`f` → `win.location.href = '?page=reply|replyAll|forward&uid=' +
  uid + '&folder=' + encodeURIComponent(folder)` (the row does not carry these
  links; the URL shape matches `view_read()`, index.php:8694).
- `v` → `win.moveTo(uid, folder)` (the `#move-dialog` is inside the mailbox).
- `e` → archive folder from `kbFolderMap()['a']` (Step 4). When absent, toast
  "No archive folder." Reuses `win.rowAction(uid, folder, '?page=move',
  'remove', { dest: archiveFolder })` — the same call the list path already
  makes (index.php:7535) `[R1]`.

**Cursor advance** — after a remove action the row disappears and the cursor
must land on the row that moved up:

- `e`/`!`/`v` are optimistic (`row-gone` immediately, index.php:6547), so call
  `kbCursorAdvance()` right after dispatch — `kbRows()` skips `row-gone`, and
  the same-index re-query lands on the next visible row.
- `#` is dialog-first: `confirmDelete` sets `dlg._run` (index.php:7717), so the
  advance hooks the **confirm**, not the dialog close — wrap `dlg._run` after
  `confirmDelete` to run the original then advance `[R2]`.

**Reader keys** — the reader is where keyboard users live, so the same keys
run against the open message `[R2]` `[R3]`. `view_read()` gains `data-uid` and
`data-folder` on the card (the folder also comes from the Back link's href).
Then, on a page with `.actions-bar`:

- `r`/`a`/`f` follow the toolbar's own reply/reply-all/forward links (they
  already carry the right hrefs).
- `s` → `markUnreadBack(uid, folder)` (posts then leaves for the listing —
  reloading would re-mark read, index.php:6575).
- `#` → `confirmDelete(uid, folder)`; `!` → `rowAction(uid, folder,
  '?page=spam', 'remove')` (no row → the `!row` branch follows the message out,
  index.php:6558); `v` → `moveTo(uid, folder)`; `e` → archive `rowAction` with
  `dest`.
- `Esc` and `u` return to the message's own folder.

### Step 4: navigation

- **`kbFolderMap()` — server-side, available on every authed page** `[R1]`
  `[R3]`: the reader and compose/settings pages render no sidebar, so `g X`
  cannot be resolved from the DOM. A pure `kb_folder_map(array $folders,
  ?array $folderMap): array` resolves the five roles to folder names — the
  folder map (`protected_folders`) wins, else the same detection the sidebar
  and onboarding use (`folder_display_name()`/`classifyFolder()`). `render_page()`
  (index.php:5454) loads `cached_folder_list($user)` and emits the result as
  `data-kb-map` JSON on `<body>`. JS parses it once into `kbFolderMap()`.
- `g X` → `openFolder('?page=folder&folder=' + encodeURIComponent(map[X]))`
  (in-place swap on a listing, full navigation elsewhere, index.php:7456);
  `g i` is the same with `INBOX`. Unknown role (a map without the folder) →
  one toast "No such folder." and the prefix resets.
- `u` → reader: the message's own folder (from the Back link); listing:
  `?page=inbox` (predictable, Superhuman-style; documented in the sheet).
- `/` → focus `.search input[name=q]` (listing only).
- `c` → `?page=compose`.
- **Reader `j`/`k`** — `read_prev_next(string $email, string $folder, int
  $uid): array` computes neighbours **by rank over the whole cached folder**,
  ordered exactly as the list (`ORDER BY date_ts DESC, uid DESC`, index.php:1808
  — `db_list_messages` is page-limited to 20, so it cannot be used as the
  window) `[R1]` `[R3]`. `view_read()` emits `data-prev` (the row above; `k`)
  and `data-next` (the row below; `j`) as `?page=read&uid=…&folder=…` hrefs. A
  missing neighbour is a no-op. Linear order, matching Gmail; no unread-skip.

### Step 5: composer send + shortcut sheet

- **Composer** (`js_inline()`): a `keydown` on `.compose-form` —
  `(ev.metaKey || ev.ctrlKey) && ev.key === 'Enter'`, with `ev.repeat` ignored
  `[R3]`, clicks the primary Send button so the existing submit pipeline
  (validation, undo interception, `markBusy`, index.php:6757) runs untouched.
  A second send while one is in flight is refused: the submit handler early-
  returns when the primary button already has `.busy` (the login form already
  does this, index.php:6606) `[R2]` `[R3]`.
- **`?` sheet**: `?` (and `Shift+/`) opens a `<dialog class="modal"
  id="kb-help-dialog">` rendered by `view_kb_help_dialog()` inside
  `render_page()`'s authed block (the `view_md_help_dialog()` precedent,
  index.php:8862). It lists `kb_shortcuts()` as a two-column table, keys in
  `<kbd>`, `aria-labelledby` pointing at the title `[R2]`. Close is the first
  focusable with `autofocus`, so `showModal()` lands there. It carries a
  one-line "Disable shortcuts" link that sets `localStorage['emmmail-kb']`
  and reloads. Open/close via `kbHelp()`.

### Step 6: tests (TDD: red first)

**unit.php**
- `kb_shortcuts()` returns one entry per key, no duplicate keys, non-empty
  labels — and every key the sheet lists has a JS binding (see js guard).
- `view_sidebar()` unchanged (no `data-kb` needed — the map is on `<body>`).
- `kb_folder_map()` resolves roles with and without a folder map; an unmapped
  role is absent.
- `render_page()` emits `data-kb-map` on `<body>` and ships `#kb-help-dialog`
  whose table covers every `kb_shortcuts()` entry; Close is first-focusable.
- `read_prev_next()` over a seeded cache returns the correct neighbours across
  a page boundary (seed 45 messages, ask for #38); edges return 0; `view_read()`
  emits `data-prev`/`data-next`/`data-uid`/`data-folder`.
- Guards: `js_inline()` ships the dispatcher, `kbEnabled`, `kbEditable` (Esc
  passthrough), `kbModalOpen`, `kbPrefix` reset, and the `kbFolderMap` wiring.

**js.php** (new group, driven through the stub's real handlers)
- Harness additions (see `[R3]` gaps below): `doc.dispatch(type, init)`
  accepting event init; `makeEl.click()` default
  (`this.dispatch('click', this, {button:0})`); `activeElement: null` default
  on `makeDoc` (assigned per test, as at tests/js.php:1329); `buildDialog`
  fixtures for `#kb-help-dialog`/`#delete-dialog`/`#move-dialog` so `showModal`
  exists (pattern at tests/js.php:488); a `win.setTimeout` override to compress
  the `g`-timeout test (pattern at tests/js.php:288); a `.nav-progress` bar in
  `buildList` (buildSidebar at 1654 already makes one); `data-kb-map` on the
  body fixture.
- `j`/`k` move `.cursor` between rows and clamp at both ends; `Esc` clears;
  `Enter`/`o` on the cursor row runs the existing click acknowledgment
  (`.mail.opening` + nav bar).
- `s`/`#`/`!`/`e`/`v` call the right global with the cursor's `uid`/`folder`;
  `#` opens `#delete-dialog` and its confirm advances the cursor; `e` uses the
  `data-kb-map` archive folder; no archive → toast.
- Reader: `data-uid`/`data-folder` present; `r`/`a`/`f` use the toolbar links;
  `s` posts markUnread and leaves; `j`/`k` navigate to `data-next`/`data-prev`.
- `g i`/`g t` set `location.href` (the stub's `win.location` is a plain
  object, assignable); an unknown `g X` resets the prefix and toasts; a stale
  `g` times out; `g` + punctuation falls through (focused search, sheet).
- `u`, `/` (focus search), `c`, `?` (dialog opens via the `showModal` stub),
  `Cmd`+`Enter` on the compose form (Send gets a click), and the guards: keys
  while typing in an input, with `metaKey`, with a `dialog[open]` present, with
  `emmmail-kb='off'`, and on a login page (no `.topbar`) all no-op.
- Swaps (cursor restore across pagination) and real `showModal` focus are
  demoted to Step 7 — the stub has no `replaceChild`, so `loadMailbox`'s swap
  path is not exercisable there, and the plan is explicit about that `[R3]`.

- `php tests/run.php` green; `php -l index.php` clean.

### Step 7: manual pass

terminal-browser (the stub has no layout engine, so these are not exercisable
there): cursor highlight + roving focus + auto-scroll; the `?` sheet and its
real `showModal` focus; native `<select>` behaviour in the move dialog — `v`
opens, arrows pick a target, Enter confirms the move (this is the one spot the
native dropdown and our Enter-to-confirm can interact; verify in Chromium and
Safari); reader `j`/`k` and `Esc`; pagination cursor restore; `Cmd`+`Enter`
send with undo-send enabled; that text selection in the composer still works;
that the layer is inert on the login page and on touch devices (the existing
row icon buttons stay the touch path).

## Outcome

| Step | Change | Status |
|---|---|---|
| 1 | `kbDispatch` + `kbEnabled`/`kbAuthed`/`kbEditable`(Esc passes)/`kbModalOpen` + `kb_shortcuts()` single table | done |
| 2 | `.mail.cursor`, roving focus, `kbCursor*`, scroll-into-view, pagination restore | done |
| 3 | cursor row actions (incl. `v`) + `#`-confirm advance + reader keys via `data-uid`/`data-folder` | done |
| 4 | `kb_folder_map()` + `<body data-kb-map>`, `g X`/`u`/`/`/`c`, `read_prev_next()` by rank + `data-prev`/`data-next` | done |
| 5 | `Cmd`+`Enter` send (repeat + busy guards); `view_kb_help_dialog()` + `?` + disable toggle | done |
| 6 | unit + js tests; harness: `doc.dispatch` init, `click()`, `activeElement`, dialog `showModal`, timer override, nav bar, `data-kb-map` | done |
| 7 | manual keyboard pass in terminal-browser (swap/cursor, dialogs, native select, send) | deferred (not run) |

Decisions recorded:
- Shortcuts are on by default; the off-switch is a `localStorage` flag set from
  the `?` sheet, not a settings page.
- No multi-select / bulk actions (`x`) in this plan — its own state model and
  bulk endpoints deserve a plan of their own.
- `s` is read-toggle (Emmmail has no star UI); `g s` = Spam and `g a` =
  Archive differ from Gmail's Starred/All Mail — the sheet marks these three
  "differs from Gmail".
- `u` on a listing always returns to Inbox (predictable), never a history jump.
- `j`/`k` clamp at the list ends; auto-pagination is a follow-up.

Review cross-check: all `[R1]`/`[R2]`/`[R3]` marks correspond to findings from
the three reviews (blocking: `:not()` stub gap; reader has no sidebar for
`g X`; `db_list_messages` is page-limited; the sheet/table drift; `Cmd`+`Enter`
double-send; `Esc` dead in editable fields; the move-dialog Enter path).