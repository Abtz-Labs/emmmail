# Folder unread counters in the sidebar

**Status:** done
**Date:** 2026-09-29

## Problem

The sidebar shows no counters, so the user cannot see how many messages need
attention.

## Decisions (agreed with the user)

- **Source:** render from the local cache (0 IMAP). The background sync also
  does a STATUS sweep of the folders that are not open, at most once per
  `COUNTS_SWEEP_INTERVAL_SECONDS` (60) per folder.
- **Which folders:** all except Sent, Trash and Archive. **Drafts shows its
  total**, not its unseen count: drafts are normally `\Seen`, so an unseen
  count would always be 0.
- **Live update:** optimistic in JS (mark read/unread, remove of a row), and
  server-side +/-1 on mark read/unread and on open, so the next render agrees.
  The next sync or sweep corrects any drift to the server value.

## Why a new table (`folder_counts`), not `folder_state.unseen`

- `folder_state.unseen` is sync bookkeeping: `sync_decide()` compares it with
  STATUS to detect change. A sweep or a +/-1 that writes it would make a
  changed folder look unchanged, and the scan would be skipped.
- A `folder_state` row for a folder that was never synced makes
  `folder_view_data()` read the folder as "known empty" (`total` null), which
  is the old empty-mailbox bug.

## Steps

1. Schema v3: `folder_counts(user_email, folder, unseen, total, checked_at)`.
   Cleared by `db_clear_cache_for_user()` and on folder delete.
2. DB helpers: `db_set_folder_counts`, `db_get_folder_counts`,
   `db_bump_folder_unseen` (clamped at 0, no row -> no-op).
3. Pure `folder_badge(name, row, folderMap): ?array` -> `['n'=>int,
   'kind'=>'unseen'|'total']` or null (excluded role / unknown).
4. `cache_mark_read(email, folder, uid, read)`: sets the cached flag and bumps
   unseen only when the cached flag actually changes. Used by
   `handle_mark_read()` and `handle_read()`.
5. `sync_folder()` writes counts from its STATUS. `counts_sweep()` STATUSes the
   due, non-excluded, non-open folders on a shared, lazy connection.
6. `handle_sync()` runs the sweep and returns `counts` (urlencoded name ->
   badge) in its JSON.
7. `view_sidebar()` gets `$counts`; links carry `data-folder`; badge is
   `<span class="folder-count" data-kind="...">N</span>`. All three render
   paths pass `db_get_folder_counts()`.
8. JS: `setBadge/bumpBadge`; toggleRead +/-1 (unseen kind), row remove -1
   (total kind always, unseen kind if the row was unread), undo reverses; sync
   response repaints all badges.
9. CSS badge; README feature list.

## Verification

- Unit: migration, helpers, `folder_badge`, `cache_mark_read`, `counts_sweep`
  with a fake connection (due/skip/interval/excluded), `view_sidebar` markup.
- JS: toggleRead bumps the badge, undo restores, sync response repaints.
- `just test` green.
