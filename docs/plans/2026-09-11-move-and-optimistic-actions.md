# Move to folder + optimistic message actions

status: done

## Why

`markAsRead/UnreadByUid()` were `: void` inside a boolean guard, so the local
cache write never ran and every flag change showed stale until the background
sync. That is fixed. What remains is that **every message action costs a full
page load**, and there is no way to move a message to an ordinary folder.

## Scope

1. **Move to folder** — new feature. No route, handler, IMAP method or UI exists.
2. **Optimistic actions** — list rows and reader stop reloading; the DOM is
   patched immediately and the POST happens in the background, with rollback.

## Steps

| # | Change | Verification |
|---|---|---|
| 1 | `folder_move_check(string $from, string $to, array $folders): string` -> `ok\|same\|unknown\|noselect` — pure, runs before any connection | unit: each branch, `\Noselect` refused, case-insensitive |
| 2 | `MailImap::moveToFolder(int $uid, string $dest): bool`; `moveToJunk()` delegates to it (third copy of the same UID->msgno->move->expunge dance) | unit: reflection + `moveToJunk` no longer duplicates |
| 3 | `?page=move` -> `handle_move()`: POST, CSRF, `selectFolder`, gate, `cache_apply_move`, flash, redirect | unit: route present; handler redirects so it is untestable in-process |
| 4 | Reader gets a **Move to** icon button opening a dialog listing target folders (reuses the `openConfirm`/`newFolder` dialog infra, not a new pattern) | unit: markup hooks; js: dialog opens, posts dest |
| 5 | `win.rowAction(uid, folder, opts)`: POST, patch the DOM, never reload. Flag toggle flips `.unread` + icon + tip; delete/spam/move fade the row out and decrement the header count | js: each mode, count decrement |
| 6 | **Rollback on failure**: a rejected or `!ok` response restores the row and shows an inline error, because an optimistic UI that lies is worse than a reload | js: failed POST restores the row |
| 7 | Reader actions navigate immediately, POST with `keepalive`, so the list is never waited on | js: navigates without awaiting the response |
| 8 | README route list + `docs/memory.md` | read back |

## Decisions taken

- **Move lives in the reader only.** Adding a per-row folder picker to every list
  row is a lot of UI for a rare action, and the row already carries three buttons.
- **The dialog reuses the existing `dialog.modal` markup**, so there is no second
  overlay pattern to maintain.
- **`\Noselect` folders are refused as targets**: STATUS/SELECT fail on them, so
  a move would half-succeed.

## Risks

- The reader's instant navigation races the server write. Mitigated by patching
  the row client-side on arrival, exactly like the optimistic list path.
- The mobile read toolbar is already two rows at 375px; an eighth action must be
  measured, not assumed.

## Outcome

- `?page=move` + `folder_move_check()` + one `moveToFolder()` that every filing
  action delegates to. Reader picker built from the cached folder list, offering
  only targets the gate accepts.
- `win.rowAction(uid, folder, url, mode)` patches the row, then posts with
  `ajax=1`. `remove` hides the row and detaches it only once confirmed, so
  rollback is one class, never a re-insert at the right index. `flag` flips
  `.unread`, the sprite href and the tooltip.
- `respond_mutation()` + `request_wants_json()`: delete/restore/spam/move answer
  JSON to the optimistic client and keep flash+redirect for a plain form post.
- `win.post`/`win.markRead`/`win.markUnread` are gone with the reload they
  existed for; `win.toggleRead` reads direction off the row.

## Deferred, decided (next plan)

- **HTML email styling is lost** by three things in `sanitize_html()`:
  `style` attributes stripped (`index.php:272`), `<style>` in `$dropEntirely`
  (`:226`), and no presentational attributes/tags in the allowlist (`:214-221`).
  The body iframe is already `sandbox="allow-same-origin"` with scripts off, so
  CSS cannot reach the app DOM; still strip `expression(`, `behavior:`,
  `-moz-binding:`, `javascript:` in `url()`, and `@import`.
- **Remote images load unconditionally.** Wanted: off by default, a per-message
  CTA to load them once, and a whitelist. **Whitelist stores the exact sender
  address** (not the domain). Both settings fit the existing per-user key/value
  `settings` table. Once CSS is allowed, `url()` is a second image channel the
  blocker must cover.
