---
status: in progress (code + tests done; manual browser pass not run)
---

# Spam from the message list, single and bulk

## Problem

The reader has a Spam button, but a message in the list can only be moved through
the folder picker. Filing one message as spam from the list costs several clicks,
and a selection of messages has no spam action at all.

## Fix

### Server

- `handle_spam()` reads the selection through `post_uids()` instead of a single
  `uid`, validates the folder name like the other bulk handlers, and files the
  whole batch with one `moveManyToFolder($uids, $junk)`.
- It resolves the Junk mailbox first. A missing Junk mailbox stays a refusal
  (`No Spam folder on this account.`), never a delete.
- Cache writes are per UID and gated on `$count > 0`; both the source folder and
  the Junk folder are invalidated.
- `MailImap::moveToJunk()` is removed: the batch path now serves both the reader
  and the list, so the single-UID wrapper had no caller.

### List row

`view_mailbox()` adds an icon-only Spam button to `.mail-actions`, after Move and
before Delete/Restore. It calls
`rowAction(uid, folder, '?page=spam', 'remove')`, so the row leaves at once and
comes back with its count and badge if the server refuses. It uses
`btn-warn-soft`, the same hover colour as the reader's Spam button, and carries
`data-tip` + `aria-label`.

### Bulk bar

`view_mailbox()` adds `data-bulk-action="spam"` between Move and Delete.
`wireMailbox()` routes it to `bulkRemove(pickUids(), bulkFolder(), '?page=spam')`:
one POST, optimistic row removal, exact rollback on a refusal. No confirm dialog,
because spam is a filing decision, not a delete.

### Spam hides its own Spam action

Inside the Spam mailbox the row button, the bulk button and the reader's Spam
button are not rendered: there is nowhere to file a message that is already
there. The list uses `$d['is_junk']`; the reader uses
`MailImap::isJunkFolder($folder)`, the same predicate the listing already uses.

## Tests (red first)

- `tests/unit.php`: the list row carries the spam `rowAction` call and the warned,
  named icon; the bulk bar offers `spam`; `handle_spam()` joins the four bulk
  handlers (CSRF, folder gate, `post_uids()`, one batch call, count answer, cache
  gate) and never falls back to `moveManyToTrash()`.
- `tests/unit.php`: the Spam mailbox renders no bulk spam and no row spam, and the
  reader in Spam renders no Spam button while keeping Unread and Delete.
- `tests/js.php` S93: two picked rows produce one POST to `?page=spam` with both
  uids, the rows leave at once and detach on success; a refusal restores the row
  and reports the reason in a toast.

## Docs

- `README.md`: the spam bullet names the list row, and the bulk bullet names spam.
- `docs/knowledge/frontend.md`: `bulkRemove()` is shared by move, delete and spam,
  and spam is the no-confirm removal.

## Verification

- `php tests/run.php` green.
- Manual browser pass owed: the row button in both themes, its position beside
  Move/Delete, and a five-button bulk bar at the narrow container breakpoints.
