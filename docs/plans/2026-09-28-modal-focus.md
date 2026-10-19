# Modal focus lands on Confirm, not Cancel

**Status:** done
**Date:** 2026-09-28

## Problem

When a modal `<dialog>` opens, `showModal()` focuses the first focusable
element — which is always the Cancel button (delete, folder, folder-map,
onboarding, move, trusted-senders). The user has to Tab to reach the action the
dialog exists for.

Since Escape already closes the modal, focus on Cancel is redundant.

## Goal

Focus the confirm button when a modal opens:
- `.btn-confirm` / `.btn-confirm-primary` in `#delete-dialog`,
  `#folder-dialog`, `#folder-map-dialog`, `#onboarding-dialog`,
  `#trusted-senders-dialog`, and `#move-dialog`.
- Exception: `#md-help-dialog` deliberately focuses its title (a table scroll
  concern) — leave it.
- Exception: `#folder-dialog` focuses its text input first (typing the name is
  the reason it opened) — confirm is the fallback, so keep the input focus
  when the field exists.

## Plan

### Step 1: `autofocus` on the confirm buttons (server markup)

The app already defeats `showModal()`'s default focus this way: `#md-help-dialog`
puts `autofocus` on its title specifically so the browser does not focus Close.
`<dialog>.showModal()` focuses the first element with `autofocus` inside the
dialog, falling back to the first focusable. So:

- `#delete-dialog` `.btn-confirm` → `autofocus`
- `#folder-map-dialog` `.btn-confirm-primary` → `autofocus`
- `#onboarding-dialog` `.btn-confirm-primary` → `autofocus` (when the cold-start
  disables Save, focus falls back to "Later", which is the only thing that works)

Untouched, deliberately:
- `#folder-dialog` — its text input is the first focusable, so `showModal()`
  already focuses it (typing the name is the job).
- `#move-dialog` — its `<select>` is the first focusable (choosing the target is
  the job), not Cancel.
- `#trusted-senders-dialog` — the only control is Close; it gets focus already.
- `#md-help-dialog` — keeps `autofocus` on its title.

### Step 2: Tests

- **unit.php**: assert the three confirm buttons carry `autofocus` in
  `render_page()` output (delete, folder-map, onboarding), and the md-help title
  still has it.

### Verification

- `php tests/run.php` green (**1905 passed, 0 failed**, 2 live suites skipped).
- `php -l` clean.
- Manual: open each modal → confirm button focused (folder dialog keeps its text
  input focused, move keeps its select); Escape closes; Enter confirms.

## Outcome

Implemented with TDD (red tests first):

| Step | Change | Status |
|---|---|---|
| 1 | `autofocus` added to the confirm button in `#delete-dialog`, `#folder-map-dialog`, and `#onboarding-dialog` | done |
| 2 | Unit tests assert the three confirm buttons carry `autofocus`, no Cancel does; md-help title keeps its own | done |

The fix reuses the pattern `#md-help-dialog` already established: `showModal()`
focuses the first element with `autofocus` inside the dialog, else the first
focusable element. So the browser handles it natively — no JS, and the reduced
surface means no behaviour to drift.

Untouched by design: `#folder-dialog` (its name input is the first focusable),
`#move-dialog` (its target select is), and `#trusted-senders-dialog` (Close is
the only control). On a cold-start onboarding where Save is disabled, focus
falls back to "Later", the only control that works.