---
status: in progress (code + tests done; manual browser pass not run)
---

# Composer autocomplete: keyboard selection

## Problem

The recipient autocomplete on the compose To/Cc/Bcc fields is mouse-only. The
suggestion rows answer `mousedown`, but `↑`/`↓` do nothing and `Enter` submits
the form instead of taking a visible address.

## Fix

`index.php`, the `[data-autocomplete]` block:

- Keep one `active` index per field, reset on every repaint and on a document
  click.
- `↓` highlights the first row when nothing is highlighted, otherwise the next,
  wrapping at the end. `↑` starts at the last row and wraps backwards.
- `Enter` picks the highlighted row and hides the list. With no highlight (or a
  hidden list) it is left to the form.
- Mouse picks go through the same `acPick()` path, so the fill-and-hide
  behaviour cannot drift between pointer and keyboard.
- `Esc` stays with `kbCloseAutocomplete()`. The field handler deliberately does
  not hide the list, or the same key would fall through and discard the draft.
- CSS: `.ac-item.ac-active` shares the hover background.

## Tests (red first)

`tests/js.php` scenario S92: `↓` highlights the first row, a second `↓` moves
on, both ends wrap, `↑` from nothing starts at the last row, `Enter` fills the
highlighted address and hides the list, `Enter` with the list hidden leaves
`defaultPrevented` false, and `Esc` drops the highlight so the next `↑` starts at
the last row again.

## Docs

- `README.md`: the contacts bullet names the arrow/Enter path.
- `docs/knowledge/frontend.md`: why `Esc` is not handled in the field.

## Verification

- `php tests/js.php` green (S92).
- `php tests/unit.php`, `php tests/imap.php` green.
- Manual browser pass owed: arrows in a real composer, the highlight in both
  themes, and `Enter` still submitting when no suggestion is highlighted.
