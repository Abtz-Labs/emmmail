# Drafts + folder management

- **Date:** 2026-09-10
- **Status:** done

## Goal

1. Save a composed message as a draft, and continue editing it later.
2. Create and delete folders from the sidebar, with the special folders
   protected against deletion.

## Decisions (asked, answered)

- Drafts: **explicit "Save draft" button only**, no autosave.
- A row in the Drafts folder **opens the composer**, not the read view.
- Create/delete folder lives **in the sidebar**.

## Part A — Drafts

IMAP cannot update a message in place. "Saving" an existing draft is therefore
APPEND a new copy, then delete the old one, in that order.

1. `MailImap::isDraftsFolder()` (pure, static, like `isSentFolder`) and
   `findDraftsFolder()` (via the existing `findSpecialFolder`).
2. `?page=saveDraft` (POST + CSRF): build the raw message exactly as `handle_send`
   does, keep the `Bcc` header (`smtp_raw_with_bcc`) so blind recipients survive
   the round trip, `appendToFolder($drafts, $raw, '\\Draft \\Seen')`, delete the
   previous copy when `draft_uid` is posted, `cache_invalidate_folder`, redirect
   to the Drafts listing.
   - Validation is deliberately weaker than send: a draft with no recipient and
     no subject is legitimate. Refuse only a completely empty message.
3. `view_compose`: a secondary "Save draft" button using
   `formaction="?page=saveDraft"` **and `formnovalidate`** — without the latter
   the `required` on To/Subject blocks saving an unfinished draft, which is the
   whole point.
4. `?page=compose&draft=<uid>`: load the draft, prefill to/cc/bcc/subject/body,
   carry `draft_uid` in a hidden field.
   - Addresses come back as header text (`Name <a@b.c>`), which would fail
     `FILTER_VALIDATE_EMAIL` on send. New pure `header_address_list()` reduces a
     header to a comma-separated list of bare addresses.
   - Attachments: reuse the existing forward mechanism verbatim
     (`$_SESSION['forward_src']` + the checkbox list), so a draft's attachments
     are re-fetched from the stored copy on save or send. Fetch before the old
     copy is deleted.
5. `handle_send`: when `draft_uid` is posted, delete the draft copy after the
   mail is away.
6. Drafts rows link to the composer: `render_folder_listing` passes `is_drafts`,
   `view_inbox` builds `?page=compose&draft=<uid>` for that folder.

## Part B — Folder management

1. Pure `folder_is_protected(string $name): bool`. Built from what already
   exists: `MailImap::isSentFolder`, `isTrashFolder`, and
   `folder_display_name()`'s canonical labels (Inbox, Drafts, Sent, Spam, Trash),
   so localized names (`Junk`, `Kosz`, `Wysłane`) are covered without a second
   hardcoded list.
2. `MailImap::createFolder()` / `deleteFolder()` — thin `imap_createmailbox` /
   `imap_deletemailbox` wrappers that also drop the per-request folder memo.
3. `?page=createFolder` / `?page=deleteFolder` (POST + CSRF):
   - name checked with the existing `imap_folder_name_is_safe()`;
   - delete refuses a protected folder **server-side** (the UI hiding the button
     is not a control);
   - local cache purged for a deleted folder (`messages` + `folder_state` rows)
     and the folder list cache dropped so the sidebar reflects it at once.
4. Sidebar: a "New folder" action, and a delete affordance on unprotected
   folders only.
5. JS: reuse the existing `<dialog class="modal">` pattern. `confirmDelete` is
   generalised into a shared confirm-and-POST used by both message delete and
   folder delete; a second dialog holds the new-folder input.

## Tests

- Pure/hermetic (`tests/unit.php`): `folder_is_protected` over Inbox, Drafts,
  Sent, Spam, Junk, Trash, Kosz, Wysłane, Archive, a user folder named
  "Sent-ish", case variants; `header_address_list` over bare, display-name,
  multi and malformed input; `view_compose` save-draft button carries
  `formaction` **and** `formnovalidate`; draft round-trip prefill;
  `view_inbox` links Drafts rows to the composer and renders the delete
  affordance only for unprotected folders.
- Wire-level (`tests/imap.php`): extend `_imap_stub.php` with `CREATE`/`DELETE`
  so `createFolder`/`deleteFolder` are asserted as real round trips, including
  the server-says-NO path.
- Integration: `handle_delete_folder()` on a protected name must refuse without
  connecting at all.

## Risks

- **Deleting a folder destroys the mail inside it.** Dovecot will delete a
  non-empty mailbox without complaint. The confirm modal must say so.
- Losing a draft's attachments on re-save is the obvious failure mode; the
  fetch-before-delete order is what prevents it, and needs a test.
- `formnovalidate` is easy to forget and silently breaks saving empty drafts.

## Verification

1. `just test` green, and red-first evidence for every new assertion.
2. Manual: compose → Save draft → Drafts shows it → open it → fields restored →
   Save draft again → still one copy, edits kept → Send → draft gone, mail in
   Sent.
3. Manual: create "Testing" → appears in the sidebar → delete it → gone. Inbox,
   Drafts, Sent, Spam and Trash show no delete affordance, and a hand-crafted
   POST for one of them is refused.

## Outcome

Implemented as planned. Full suite: **4 suites, 701 tests, 0 failed, 2 skipped**
(up from 613).

Deviations and notes worth keeping:

- `compose_gather_attachments()` was extracted from `handle_send` so the draft
  path reuses it byte for byte. It reads the carry-over source from the session
  and is called **before** the old draft copy is deleted.
- `cache_refresh_folder_list()` re-LISTs after a create or delete, but refuses to
  cache an empty result: an empty LIST means the round trip failed, and caching it
  would blank the sidebar for the whole 900s TTL.
- `createFolder`/`deleteFolder` drain c-client's error stack on failure. Without
  it the queued "CREATE refused" was dumped at PHP shutdown and blamed on
  whatever ran last (visible in the test output before the fix).
- The DOM stub in `tests/js.php` was missing `removeAttribute`, which only
  surfaced once a dialog was actually closed by a test. Added to the stub rather
  than worked around in the test.
- Not covered end to end: `handle_delete_folder()` refusing a protected folder.
  `redirect()` calls `exit`, so a handler-level test would kill the suite. The
  rule itself is covered by `folder_delete_check()` unit tests, and the handler
  calls it before it builds a connection.
