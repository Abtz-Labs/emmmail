# Settings improvements: signature, sync period, undo send, auto images, folder map

status: done

## Why

The settings page has only three fields (messages per page, display name, remote
images). Five new settings would significantly improve UX:

- **Signature** -- every outbound message gets the user's signature appended
  before the quoted/forwarded content, saving repeated typing.
- **Sync period** -- the sync intervals are hard-coded constants; users should
  choose how often the app auto-syncs.
- **Undo cancellation period** -- Gmail's killer feature: hold outbound messages
  in Outbox for a configurable delay so users can cancel and edit.
- **Auto show images** -- the remote-images toggle is off by default and only
  per-sender; users who trust all senders should flip one switch.
- **Protected Folders map** -- the current folder-name guessing fails for
  providers that use non-standard names (e.g. "Deleted Items" instead of
  "Trash"); users should map each role to their actual folder name.

## Decisions

- All five are **new EAV keys** in the existing `settings` table -- no schema
  migration required (SCHEMA_VERSION stays at 2).
- **Signature**: stored as raw text (multi-line). Appended at the prefill/view
  level (`reply_form_data`, `handle_forward`, blank compose) so the user sees
  and can edit it in the textarea before sending. NOT appended in
  `smtp_build_raw_message` -- that would hide it from the sender.
- **Sync period**: dropdown with pre-defined intervals (15s, 30s, 60s, 120s,
  300s, manual-only). Stored as seconds. Read where `SYNC_MIN_INTERVAL_SECONDS`
  and `SYNC_FULL_INTERVAL_SECONDS` are currently used.
- **Undo cancellation period**: stored as seconds (0 = off, 5, 10, 15, 30).
  When > 0, `handle_send` delays the actual SMTP send by posting to a holding
  queue (session-stored) and starts a JS countdown timer. The user can hit
  "Undo" during the window to cancel.
- **Auto show images**: boolean toggle (`0`/`1`). When ON, `images_allowed()`
  returns `true` regardless of the whitelist. The per-sender trust still works
  when this is OFF.
- **Protected Folders map**: JSON object mapping role keys (`sent`, `drafts`,
  `spam`, `trash`, `archive`) to folder names. When set, `folder_is_protected()`
  and the `find*Folder()` methods check the user map first. The settings UI
  shows a modal (like the screenshot) with dropdowns populated from the user's
  actual folder list.

## Steps

| # | Change | Verification |
|---|---|---|
| 1 | **Signature setting** -- new key `signature`. Add `<label>` + `<textarea>` in `view_settings()`. Persist in `handle_settings()` POST branch. Read via `user_setting('signature', '')` in `reply_form_data()`, `handle_forward()`, and blank `handle_compose()`. | unit: `view_settings` renders textarea; `reply_form_data` body ends with signature; forward body ends with signature; blank compose body is signature; empty signature = no change |
| 2 | **Auto show images setting** -- new key `auto_show_images`. Add toggle in `view_settings()`. Persist in `handle_settings()`. Modify `images_allowed()` to accept `$autoOn` param; when true, return `true` always. | unit: `images_allowed` returns true when `$autoOn=true` even without whitelist; unchanged when false |
| 3 | **Sync period setting** -- new key `sync_period_seconds`. Add `<select>` dropdown in `view_settings()` with options 15/30/60/120/300/manual(0). Persist in `handle_settings()`. Read in JS sync interval and `handle_sync` throttle. Define `SYNC_PERIOD_OPTIONS` constant array. | unit: dropdown renders with 6 options; handler persists value; JS reads the setting |
| 4 | **Undo cancellation setting** -- new key `undo_period_seconds`. Add `<select>` in `view_settings()` with options 0(off)/5/10/15/30. Persist in `handle_settings()`. Modify `handle_send` to store outgoing message in `$_SESSION['undo_queue']` when period > 0, return JSON with countdown. Add `?page=undoSend` route that cancels. Add JS countdown bar in compose view. | unit: handler stores in undo queue when period > 0; undo route cancels and removes from queue; period=0 sends immediately |
| 5 | **Protected Folders map** -- new key `protected_folders`. JSON string. Add "Manage folders" button in `view_settings()` that opens a modal. Modal shows 5 role dropdowns (Sent, Drafts, Spam, Trash, Archive) populated from `cached_folder_list()`. Persist as JSON in `handle_settings()`. Modify `folder_is_protected()` and `find*Folder()` methods to check user map first. | unit: `folder_is_protected` uses user map when set; `findSentFolder` etc. check user map; empty map falls back to defaults |
| 6 | **Tests** -- add test cases in `tests/unit.php` for each new setting: view rendering, handler persistence, pure function integration. | `just test unit` passes |
| 7 | **README + memory** -- update route table and session memory. | read back |

## Undo send detail

The undo mechanism uses a two-phase send:

1. `handle_send` stores the built raw message + recipients in
   `$_SESSION['undo_queue'][$token]` with an expiry timestamp.
2. Returns a JSON response (when `ajax=1`) with `{undo: true, token, seconds}`.
3. JS renders a countdown bar at the bottom of the compose area. During the
   window, "Undo" button POSTs `?page=undoSend&token=<t>`.
4. `handle_undo_send` removes the entry from the session and returns success.
5. When the timer expires, a JS `setTimeout` fires `?page=flushUndo` which
   actually sends the message and removes it from the session.
6. If period is 0, the message sends immediately (current behaviour).

## Protected folders detail

The folder map is a JSON object:
```json
{
  "sent": "Sent Messages",
  "drafts": "Draft",
  "spam": "Junk",
  "trash": "Deleted Items",
  "archive": "All Mail"
}
```

The `find*Folder()` methods (`findSentFolder`, `findDraftsFolder`, etc.) already
iterate the folder list with name matching. The user map overrides the keyword
list: when a map entry exists, only that exact name is matched (no substring
fallback). `folder_is_protected()` adds the user-mapped names to its check.
INBOX is always protected regardless of the map.

## Risks

- **Undo send reliability**: if the user closes the tab, the message stays in
  the session until the server-side timer flushes. This is safe because
  `$_SESSION` is server-held. A crashed server would lose the queue, which is
  acceptable (messages in Outbox are already non-delivered).
- **Sync period too aggressive**: the 15s minimum keeps the throttle floor;
  going below that would DDoS the IMAP server. The dropdown enforces this.
- **Folder map errors**: a user mapping two roles to the same folder would
  cause collisions. The UI should warn on duplicate values.

## Outcome

Verified in browser and unit tests:

| Setting | Default | UI control | Storage key |
|---|---|---|---|
| Signature | empty | textarea | `signature` |
| Auto show images | off | toggle | `auto_show_images` |
| Sync period | 15s | select | `sync_period_seconds` |
| Undo period | off (0s) | select | `undo_period_seconds` |
| Protected folders | empty (use defaults) | modal with 5 dropdowns | `protected_folders` |

- All settings are EAV strings, no migration.
- Signature appends at view level, visible and editable before send.
- Undo send uses session queue + JS countdown, zero extra DB writes.
- Folder map overrides the hard-coded keyword lists, INBOX always protected.
