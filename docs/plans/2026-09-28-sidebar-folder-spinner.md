# Sidebar folder navigation: icon becomes a spinner on click

**Status:** done
**Date:** 2026-09-28

## Problem

Clicking a folder in the sidebar is a full `?page=folder` navigation, and a
cold cache makes the server LIST + SELECT + fetch before it can paint — the
slowest wait in the app. But the sidebar link gives no acknowledgement: no
spinner, no progress bar. Only the browser tab shows something is happening.

The app already has the vocabulary for this:

- `markBusy(el)` swaps the control's `<use>` icon to `#tabler-progress`, adds
  `.busy`, and starts the `.nav-progress` bar; it remembers the old icon in
  `data-icon-was`.
- `clearBusy()` restores every `.busy` icon and stops the bar. It runs on
  `pageshow`, so a page restored from the browser cache after Back never shows a
  stuck spinner.
- The reader toolbar, search, images-CTA and hint use this via
  `roundTripClick()`. The `.mail-list` rows use the sibling `.mail.opening`
  pattern. The **sidebar folder links use neither** — the gap.

## Goal

When a sidebar folder link is clicked, its icon becomes the spinner and the
progress bar runs, like the reader toolbar. Because the click navigates away,
the new page renders a fresh sidebar (icon already reset); if the browser
restores this page from cache, `pageshow` → `clearBusy()` resets it.

## Plan

### Step 1: `view_sidebar()` — mark folder links (index.php ~line 7917)

Give each folder `<a href="?page=folder&folder=...">` a class `folder-link`
(no inline JS; the shared handler binds once). Compose, New folder, and the
folder-delete buttons keep their own behaviour and are not affected.

### Step 2: CSS — spin the sidebar icon while `.busy`

Extend the existing spin rule and its reduced-motion twin:

```
.sidebar .folder-link.busy svg.ti { animation:sync-spin .7s linear infinite; }
```

(no `.btn`/`.icon-btn`, so the current rules miss it).

### Step 3: JS — bind the sidebar folder links to `markBusy`

In `js_inline()`, next to the `.mail-list` click acknowledgement:

- `$('.sidebar').on('click', '.folder-link', ...)` → `markBusy(this)`.
- Ignore modified clicks (meta/ctrl/shift/alt) exactly like the mail rows: they
  open a new tab and this page stays, so marking it busy would be a lie.
- `clearBusy()` on `pageshow` (already wired) resets the icon.

### Step 4: Tests

- **unit.php**:
  - `view_sidebar()` folder links carry `folder-link`.
  - `css_inline()` has `.sidebar .folder-link.busy svg.ti` and its
    reduced-motion twin.
  - `js_inline()` binds `$('.sidebar').on('click', '.folder-link'`.
- **js.php** (new S35):
  - build a sidebar with two folder links + a nav-progress bar;
  - click a folder link → link has `.busy`, its icon href is `#tabler-progress`,
    the bar runs, the other link is untouched;
  - `pageshow` → `.busy` cleared, icon restored, bar stopped;
  - a modified click marks nothing.

### Verification

- `php tests/run.php` green (**1898 passed, 0 failed**, 2 live suites skipped).
- `php -l` clean.
- Manual: click a slow folder → its icon spins + top bar runs; new page shows a
  normal icon; Back restores the page with no stuck spinner.

## Outcome

All steps implemented with TDD (red tests first):

| Step | Change | Status |
|---|---|---|
| 1 | `view_sidebar()` folder links carry `class="folder-link"` | done |
| 2 | CSS: `.sidebar .folder-link.busy svg.ti` spins (plus reduced-motion twin) | done |
| 3 | JS: `$('.sidebar').on('click', '.folder-link', ...)` → `markBusy(this)`, ignoring modified clicks; `pageshow` (already wired) restores via `clearBusy()` | done |
| 4 | Unit tests (markup class, CSS rule, JS binding) + JS S35 (busy on click, icon→spinner, bar runs, pageshow resets, modified click untouched) | done |