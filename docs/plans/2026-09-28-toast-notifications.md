# Floating toast notifications (no layout shift)

**Status:** done
**Date:** 2026-09-28

## Problem

Notifications are currently static, in-flow elements:

1. **Server flash** — `render_page()` prints `.alert.alert-success` /
   `.alert.alert-error` blocks above `<main>`. They push the content down and
   vanish only on the next navigation.
2. **`rowError()`** — writes into a pre-rendered `.row-error` box inside the
   list (`view_inbox`) and the reader (`view_read`). When it appears it pushes
   the rows/message down.
3. **Login flash** — `view_login()` renders `.flash` inside the login box,
   growing the box.

Each of these shifts the layout when it appears or disappears.

## Goal

Replace them with **floating toasts**: a fixed-position `.toast-container` that
never participates in the document flow, so a toast appearing or leaving never
moves the page. Server-rendered flashes become toasts too (works with JS off),
and JS-created ones auto-dismiss on a timer and on click.

---

## Plan

### Step 1: CSS (index.php, replace the "Flash / alerts" block ~line 6092)

- `.toast-container` — `position:fixed`, top-right under the topbar,
  `pointer-events:none` (the empty container never blocks clicks),
  `z-index` above content.
- `.toast` / `.toast-success` / `.toast-error` — card look with the existing
  success/error tokens; `pointer-events:auto`.
- `.toast.hiding` + `toast-in` keyframe — fade/slide; `prefers-reduced-motion`
  guard. Fixed position means zero layout shift by construction.
- Remove the now-dead `.alert`, `.flash`, `.login-box .flash` rules.

### Step 2: `render_page()` renders the toast container (index.php ~line 5473)

Always emit `<div class="toast-container">`, and put the flash inside it as
`.toast.toast-success` / `.toast.toast-error` when present. Drop the two `.alert`
blocks. `role="status"` / `role="alert"`.

### Step 3: Login flash becomes a toast (index.php)

- `view_login(string $csrf)` — drop the `$flash` parameter and its `.flash`
  markup (the toast container owns flash now).
- `render_login()` — stop calling `flash_get()` itself; `render_page()` already
  consumes it and renders the toast.

### Step 4: `rowError()` writes a toast (js_inline ~line 6413)

- `win.toast(msg, type)` — appends a `.toast` to the container, auto-dismisses
  after 4s (success) / 6s (error) with a fade, and dismisses on click. The
  delays are read from `win.__toastMs` (defaulting to the real values) so tests
  can shorten them without waiting.
- `rowError()` delegates to `win.toast(..., 'error')`.
- On ready, any server-rendered toasts are dismissed on the same schedule.
- Remove the `.row-error` boxes from `view_inbox()` and `view_read()`.

### Step 5: Tests

- **unit.php**:
  - `view_login()` new signature (2 call sites).
  - `render_page()` ships a `.toast-container`; a flash renders as `.toast`.
  - list/reader no longer render a `.row-error` box.
  - `js_inline()` ships `win.toast`.
- **js.php**:
  - `buildList` / `buildReaderActions` build a `.toast-container` instead of
    `.row-error`.
  - S17/S18/S21/S24 assert `.toast-error` instead of `.alert-error`.
  - New S34: `win.toast` creates, click-dismisses, and auto-dismisses (delays
    shortened via `win.__toastMs`).
- Harness: `process.exit(0)` after the report so pending toast timers do not
  stretch the run.

### Verification

- `php tests/run.php` green (**1878 passed, 0 failed**, 2 live suites skipped).
- `php -l` clean on index.php, tests/unit.php, tests/js.php.
- Manual: delete a message offline → error toast floats over the list, no rows
  move; a server flash (e.g. "Settings saved.") arrives as a toast and fades;
  login error is a toast over the login box.

## Outcome

All steps implemented with TDD (red tests first):

| Step | Change | Status |
|---|---|---|
| 1 | CSS: `.toast-container` (fixed, top-center, `z-index:200`, pointer-events pass-through), `.toast` / success / error, `.hiding` fade, reduced-motion guard; static `.alert`/`.flash` rules removed | done |
| 2 | `render_page()` ships `.toast-container` and puts flash in it as `.toast-success`/`.toast-error` (role=status/alert); no in-flow alert blocks | done |
| 3 | `view_login(string $csrf)` dropped `$flash` and its `.flash` markup; `render_login()` lets `render_page()` consume and toast the flash | done |
| 4 | `win.toast(msg, type)` + `rowError()` delegates; server-rendered toasts auto-dismiss on ready; `.row-error` boxes removed from `view_inbox()`/`view_read()` | done |
| 5 | unit tests (view_login signature, render_page toasts, no `.row-error`, `win.toast` in JS, toast CSS) + JS S17/18/21/24 → `.toast-error`, new S34 (create, click-dismiss, auto-dismiss via `win.__toastMs`); harness `process.exit(0)` after report | done |

Notes:
- Toasts are `position:fixed` so they never shift the page by construction.
- The container is centered (top-center) with `z-index:200`, above tooltips (60)
  and autocomplete (50), still under native `<dialog>` top layer.
- Delays are `win.__toastMs` (default 4s success / 6s error), overridable in
  tests to avoid real waits.
- The js harness now exits explicitly after the report so any pending toast
  timers do not stretch the run.