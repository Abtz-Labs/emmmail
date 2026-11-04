---
status: in progress (code + tests done; manual browser pass not run)
---

# Selection shortcuts, Back speed, Spam destination, bulk count

## Problems

1. A list shortcut acts on the cursor row only, even when several rows are selected.
2. Going Back from a message to a partially cached folder fetches a live IMAP page on
   every visit. It can wait on the server although cached rows exist.
3. Spam from the reader returns to the source folder, including Spam itself.
4. A bulk removal hides the rows at once, but the "Selected" count and the bar stay
   until the server answers.

## Fixes

### Selection-aware shortcuts

`index.php`, `kbCursorAction()`: when `pickCount() > 0`, the list keys use the
bulk paths.

- `s` → `bulkAction('read'|'unread')`, directed by whether any selected row is unread.
- `v` → `bulkMoveTo()`.
- `#` → `bulkDelete()`.
- `!` → `bulkSpam()` (new, also used by the bulk button).
- `e` → `bulkArchive()` (new, uses the mapped archive folder).

With no selection the cursor behaviour is unchanged.

### Back to a partially cached folder

`folder_view_data()`: page 1 is served from any cached rows. The live fallback now
starts when `$offset > 0` and the page reaches past the cache. A folder left one
message short by a move no longer costs a live IMAP page on every Back. Deeper
pages keep the full-window rule.

### Spam leaves for the Inbox

- `rowAction()` takes an optional `next` URL and uses it when the reader has no row
  to patch.
- The reader's Spam form passes `'?page=inbox'`. Other reader actions still return
  to their own folder.
- `handle_spam()` also redirects a successful no-JS form post to `INBOX`.

### Bulk selection clears with the repaint

`bulkRemove()` calls `pickReset()` right after the rows are painted, before the
request. Its `undo()` and success path no longer reset again: a refusal must not
wipe a new selection made while the request was out.

## Tests (red first)

- `tests/js.php` S94: with two rows selected, `#` opens one counted confirm and
  posts both uids; `!`, `e`, `v` and `s` carry both uids. The single-row S39
  scenarios stay green.
- `tests/js.php` S95: Spam from the reader lands on `?page=inbox`; another reader
  action still lands on its own folder.
- `tests/js.php` S93: the count, the bar and every box clear in the same frame as
  the row removal.
- `tests/imap.php`: a page-1 render with 6 of 7 rows cached makes zero IMAP
  connections; page 2 past the partial window still goes live.
- `tests/unit.php`: the reader Spam form carries the Inbox target, and
  `handle_spam()` redirects a successful post to Inbox.

## Docs

- `README.md`: row shortcuts follow the selection.
- The in-app `?` sheet: the same note.
- `docs/knowledge/frontend.md`: selection-aware keys, bulkRemove repaint reset, and
  the reader Spam `next`.
- `docs/knowledge/imap.md`: page 1 may answer from a partial cache.

## Verification

- `php tests/run.php` green.
- Manual browser pass owed: select rows and use `#`/`!`/`e`/`v`/`s`, Back from a
  Spam message, Spam from the reader, and a bulk action's count.
