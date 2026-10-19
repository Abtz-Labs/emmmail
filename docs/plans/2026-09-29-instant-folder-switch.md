# Instant in-place folder switch

**Status:** done
**Date:** 2026-09-29

## Problem

Navigating between cached folders still feels like a live load on click:

1. A sidebar folder click is a **full page navigation** (`?page=folder&folder=X`),
   so even a warm render pays the HTML round trip, a full repaint, JS re-init
   and a sidebar scroll reset.
2. After every paint, `js_inline()` runs a background sync; when it reports
   `changed > 0` it does `win.location.reload()` (index.php:7469) — a **second
   full load** on every folder where anything arrived since the last sync.

## Goal

- Cached folders switch **in place**: click a sidebar folder, fetch the new
  listing via AJAX, swap only the `<section class="mailbox">`, update the URL
  with `history.pushState`, keep the sidebar/back button working.
- The background sync stops reloading the page; when something changed it
  refreshes the open listing in place instead.
- A cold folder, a network failure, or a non-listing page (read/compose/
  settings) falls back to the normal full navigation. Modified clicks
  (meta/ctrl/shift/alt) still open a new tab.

## Plan

### Step 1: server — render the mailbox section alone (index.php)

- Extract `view_mailbox(array $d): string` from `view_inbox()`
  (index.php:8010): everything after `view_sidebar(...)` up to and including
  `</section>`. `view_inbox()` becomes `view_sidebar(...) . view_mailbox($d)
  . '</div>'` — byte-for-byte the same output.
- Extract the listing data assembly from `render_folder_listing()`:
  `folder_listing_data(string $folder): array`
  (page, perPage, `folder_view_data` with window/full_interval/backfill=0).
- `folder_listing_view_data(array $data, string $folder, string $title): array`
  — the render payload `render_folder_listing()` feeds `render_inbox()`.
- `folder_listing_json(string $folder, string $title): array` — the AJAX
  payload: `ok`, `html` = `view_mailbox(...)`, `title` (with APP_NAME suffix
  for `document.title`), `error`.
- `handle_folder()` routes `?ajax=1` (GET) to
  `json_out(folder_listing_json(...))`, after `require_login()`.

### Step 2: JS — swap the mailbox in place (js_inline())

- `loadMailbox(href, opts)`: `$.get(href + 'ajax=1')`; on success replace the
  `.mailbox` section (`box.parentNode.replaceChild(fresh, box)`), set
  `document.title`, move the sidebar `.active` entry, toast `d.error`,
  `history.pushState` when `opts.push`, reset scroll, re-wire the mailbox.
  Resolves `true` on a swap, `false` when the caller must fall back to a full
  navigation.
- `openFolder(href)`: `loadMailbox(href, {push:true})`; on failure
  `win.location.href = href`.
- Sidebar folder-link handler: keep `markBusy(this)`; intercept (preventDefault
  + `openFolder`) only when the current page is a listing (`doc.querySelector
  ('.sync')`), else full navigation as today.
- Pagination: `$('.mailbox').on('click', '.pagination a', ...)` — intercept
  only hrefs containing `page=folder`; search pagination stays a full nav.
- `popstate`: on a listing, `loadMailbox(location.href)`; else full navigation.
- Background sync `run()`: `changed > 0` → `loadMailbox('?page=folder&folder='
  + folder)` instead of `win.location.reload()`; if the swap fails, reload as
  the honest fallback. One refresh per page (`reloaded` guard stays).

### Step 3: JS — mailbox bindings survive a swap

The `.mailbox` section is replaced wholesale, so its element-bound listeners
die with it. Extract the swap-sensitive wiring into `wireMailbox()` (called at
boot and after every swap), binding only to elements present inside the fresh
section:

- the `.mail-list` `a.mail-main` click acknowledgement;
- the `roundTripClick` list (`.actions-bar`, `.images-cta`, `.search`,
  `.hint`) + `.search` submit `markBusy`;
- the `#move-dialog` Cancel/Confirm wiring (was inside `$.ready`);
- `wireSync()` — the background-sync block, extracted from index.php:7436 so a
  fresh `.sync` chip (new `data-folder`) starts working immediately.

## Verification

- **unit.php**: `view_mailbox`/`view_inbox` shape; `folder_listing_json` over a
  warm cache returns `ok`, a `<section class="mailbox">` html, an APP_NAME
  title and no error; `js_inline()` ships the interception + `loadMailbox`.
- **js.php** (new S36): folder click on a listing preventDefaults and fetches the
  `ajax=1` URL; on a non-listing page it does not; a `page=folder`
  pagination click intercepts, a `page=search` one does not; `changed > 0`
  triggers an in-place refresh attempt (folder-ajax fetch) before any reload;
  `popstate` on a listing fetches the current URL. S5 updated: `changed > 0`
  no longer reloads directly.
- Real swap, title, sidebar-active and back-button behaviour verified in
  terminal-browser (the DOM stub has no HTML parser, so `innerHTML` swaps are
  not exercisable there — same convention as the markdown editor).
- `php tests/run.php` green; `php -l index.php` clean.

## Outcome

All steps implemented with TDD (red tests first). Suite **1935 passed, 0
failed**, 2 live suites skipped.

| Step | Change | Status |
|---|---|---|
| 1 | `view_mailbox()` extracted; `folder_listing_data()` / `folder_listing_view_data()` / `folder_listing_json()`; `handle_folder()` routes `?ajax=1` | done |
| 2 | `loadMailbox()` / `openFolder()`; sidebar folder-link + pagination interception; `popstate`; background sync refreshes in place with a reload fallback | done |
| 3 | `wireMailbox()` (round-trip list, `.search` submit, `.mail-list` ack, pagination, `#move-dialog`, `wireSync`) re-wired after every swap | done |
| 4 | Tests: unit (`view_mailbox`, `folder_listing_json`, js guards) + js S36a-d + S5 revision | done |

Remaining manual check (needs live IMAP credentials, which the smoke suite also
skips): click through several folders and Back in a real browser and confirm
the swap, title, sidebar-active entry and scroll position.