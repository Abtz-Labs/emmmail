# Nord redesign — modern Gmail-style shell

**Status:** done
**Date:** 2026-09-11

## Goal

Refresh the interface to look modern and neat, inspired by Gmail, Inbox and
Hey.com, using the Nord palette (light + dark). Keep the single-file
architecture, the routing, and every behavior guarded by the existing tests.

## Decisions

- **Both themes.** Light Nord by default, dark Nord when the OS asks for it,
  plus a topbar toggle persisted in `localStorage` (`emmmail-theme`). An inline
  bootstrap script applies the stored theme before first paint (no flash).
- **Full Gmail-style shell.** App bar (hamburger, brand, theme toggle, user,
  logout), a folder sidebar, a mail list with sender avatars and hover actions,
  and a reading-pane layout for a single message.
- **No backend changes.** Avatars, dates and tones are pure view helpers.

## Constraints (tests that must stay green)

- `view_inbox()`: no `confirm(`, `data-uid`/`data-folder`/`data-delete`,
  folder management strings, search form fields, `<h2>Title <small>(`,
  `progress-check` sync button.
- `view_read()`: no `confirm(`, `data-uid`/`data-folder`/`data-delete`,
  `<strong>Bcc:</strong> ...`, `class="body-iframe srcdoc"`,
  `sandbox="allow-same-origin"`, raw `?page=replyAll&uid=..&folder=..`.
- `view_compose()`: Bcc label/input/autocomplete, `formaction`, `formnovalidate`,
  `draft_uid`, threading fields.
- `render_page()`: always emits the shared JS (`Em.prototype.each`).
- `css_inline()`: `dialog.modal`, `position:fixed`, `transform`,
  `:has(dialog.modal[open])`, `overflow:hidden`; `.body-iframe` must NOT set
  `overflow:hidden`.

## Steps

1. Tests first: dual-palette CSS, theme bootstrap/controls, new sprite icons,
   avatar helpers.
2. Add Tabler icons: `menu-2`, `sun`, `moon`, `logout`, `plus`.
3. Add pure helpers `avatar_initial()` / `avatar_tone()` / `list_date()`.
4. Rewrite `css_inline()` around Nord tokens for both themes.
5. Rebuild the shell in `render_page()` and the view builders.
6. Add theme + sidebar JS to `js_inline()` (guarded for the Node stub).
7. Run `just test`; update `docs/memory.md`.
