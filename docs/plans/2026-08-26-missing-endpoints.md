# Missing API Endpoints Plan

**Date:** 2026-08-26
**Status:** done

## Context

The old implementation (`index.old.php`) has several API endpoints that were not carried over to the new implementation. These are needed for the frontend to function properly.

## Endpoints Implemented

| Endpoint | Auth | Purpose |
|----------|------|---------|
| `ping` | public | Health check (version + time) |
| `setup` | public | Check if first-time setup needed (no accounts) |
| `expunge_folder` | session | IMAP EXPUNGE (permanently delete trash) |
| `rename_tag` | session | Rename a tag |
| `delete_tag` | session | Delete a tag |
| `tag_message` | session | Add tag to message |
| `untag_message` | session | Remove tag from message |
| `batch_tag` | session | Batch tag/untag messages |
| `upload_attachment` | session | Upload file for compose |
| `check_update` | session | Check for new version |
| `apply_update` | session | Download and apply update |

## Skipped

- `auth_status` — The new `session` endpoint already covers this. `setup` handles the first-time check.
- `send_message` / `schedule_send` / `cancel_send` / `process_outbox` — The new `compose` endpoint handles sending. Outbox/scheduling is a future feature.

## Implementation Order

1. `ping` + `setup` (public, simple)
2. `rename_tag` + `delete_tag` (tag CRUD)
3. `tag_message` + `untag_message` + `batch_tag` (message tagging)
4. `expunge_folder` (IMAP operation)
5. `upload_attachment` (file upload)

## TDD Approach

For each endpoint:
1. Write tests (RED)
2. Implement (GREEN)
3. Verify all tests pass
