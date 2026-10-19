# Solid toasts, and a login form that remembers the servers

**Status:** done
**Date:** 2026-10-02

## Problem

1. A toast is `background:var(--success-soft)` / `var(--danger-soft)` — both are
   `rgba(...)` at 12–20%. The toast is `position:fixed` over the list or the
   reader, so the message underneath shows through the message text.
2. The login page remembers the last email in `localStorage`
   (`emmmail-last-email`), but not the preset. Every visit lands on **Migadu**,
   and the custom IMAP/SMTP fields are empty, so a return visit to a custom
   server means retyping six values.

## Goal

- An opaque toast: solid background, readable text, the type carried by the
  border.
- The preset is remembered like the email. When it is **Custom settings…**, the
  six IMAP/SMTP fields are remembered too.

## Why localStorage and not the settings table

`handle_login()` already stores `imap_*` / `smtp_*` per account, and
`view_login()` reads them back — but only when `$email` is in `$_POST`, which is
true only after a failed attempt. A fresh `?page=login` has no email to look up,
because the remembered email lives in the browser, not the session. The preset
has the same shape of problem, so it gets the same memory.

---

## Plan

### Step 1 — CSS: an opaque toast (index.php ~line 6493)

- `.toast` — `background:var(--surface)` (solid white / solid `nord1`) and
  `color:var(--text)`, `border:1px solid var(--border)`.
- `.toast-success` / `.toast-error` — drop the `-soft` background and the
  hard-coded `rgba()` border; they only set `border-color:var(--success)` /
  `var(--danger)`.

Text stays `--text` on purpose: `--success` on `--surface` is 3.7:1 at
`.88rem`, so colouring the text would trade the translucidity problem for a
contrast one. The border carries the type.

### Step 2 — JS: remember the preset and the custom fields (index.php ~line 6962)

Inside the existing `$('#login-email')` guard:

- Read `emmmail-last-preset` and `emmmail-last-custom` (a JSON object keyed by
  the form's own field names).
- Apply the preset to `#login-preset`, then set `.custom-servers` display from
  it, because setting `.val()` fires no `change` event.
- Fill each `[name="imap_server"]`-style field from the saved object. A fresh GET
  renders the ports and encryption as *defaults*, not blanks, so "fill only when
  empty" would lose the remembered values: overwrite instead. The server only
  renders custom values after a failed POST, and the submit handler has already
  saved exactly those.
- On submit, save the preset and all six values beside the email.
- Two unknown-value guards, both the same shape: a `<select>` whose stored value
  has no option reports `''` and then renders blank, so a value the release does
  not offer falls back to what the server sent. Without them a renamed preset
  key, or the one-option SMTP encryption select, leaves an empty dropdown.

### Step 3 — Tests

- **unit.php**, in the toast CSS group:
  - `.toast` names `background:var(--surface)` and `color:var(--text)`
  - `.toast-success` / `.toast-error` name `border-color:var(--success|--danger)`
  - neither typed rule carries a `-soft` token
- **unit.php**: the six custom controls are addressable by `name` — the selector
  the JS uses, which the js suite cannot see.
- **js.php**, new S69 after S68: remembered preset applied + custom fields
  revealed; custom values restored; nothing restored when nothing was stored;
  submit saves preset and all six fields.
- **js.php** harness: the stub's `<select>`/`<option>` setters recursed into each
  other for any select with children. It only ever met childless ones, so the
  crash was latent; S69 is the first fixture with options. Guarded by
  "already reads this value".

### Verification

- `php tests/run.php` green (**2558 passed, 0 failed**, 2 live suites skipped).
- `php -l` clean on index.php, tests/unit.php, tests/js.php.
- Browser (dev server, Chromium): login → Gmail-style presets and the custom
  block restore on reload; a submit against a fake host saves preset + all six
  values and re-renders with `custom` selected; the toast computes
  `bg=rgb(255,255,255) text=rgb(46,52,64) border=rgb(95,143,104)` in light and
  `bg=rgb(59,66,82) text=rgb(236,239,244)` in dark.

## Outcome

| Step | Change | Status |
|---|---|---|
| 1 | CSS: `.toast` is `var(--surface)` on `var(--text)` with a `var(--border)` border; the two typed rules only set `border-color` | done |
| 2 | JS: `emmmail-last-preset` + `emmmail-last-custom` (six fields by `[name]`), restored on ready with a blank-select guard, saved on submit | done |
| 3 | unit tests (toast opacity/border, the six `name`s) + js S69 + the stub select recursion guard | done |

Notes:
- No PHP change was needed: the six `name`s already existed, and a PHP-side test
  now pins them so a rename cannot silently break the JS.
- The two unknown-value guards are not unit-tested — the stub's select reports a
  value it has no option for, so the js suite cannot produce the condition they
  exist for. Both were confirmed in a real browser instead.