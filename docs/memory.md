# Emmmail — Session Memory

## Project Overview
- **Goal:** One-file, mobile-first, Gmail-inspired web email client with IMAP/SMTP
- **Tech:** PHP 8.1+, pure PHP IMAP/SMTP (stream_socket_client), Vanilla JS, SQLite
- **Auth:** Session-based authentication with IP binding
- **Philosophy:** Single `index.php`, zero deps, TDD

## Session 5 (2026-08-25) — Rewrite: API-first
- Started from scratch (old files renamed to `*.old`)
- API: `index.php?action=<action>`, JSON POST body
- Phase 1: `auth` endpoint — tests IMAP + SMTP connection, persists account to SQLite
- Infrastructure: `.env` parser, AES-256-GCM encryption, SQLite schema (accounts, login_attempts), IMAP/SMTP clients, utility functions
- Front controller guard: `if (php_sapi_name() !== 'cli' || isset($_GET['action']))` — prevents `exit` during test bootstrap
- `apiRequest()` test helper reads from exec stdout (not file) since `exit` kills subprocess before file write
- 82 tests passing: .env parser, encryption, DB schema, IMAP role detection/tokenizer/fetch parser, auth endpoint, unknown action, HTML escaping, display names, address parsing, filename sanitization, date normalization, subject normalization, blocked attachments, rate limiting

## Session 6 (2026-08-26) — tests.php code review
- Full review of tests.php against index.php; suite passes 197/0/3 by default
- Latent bugs (only trigger when EMMMAIL_TEST_* set): testSyncEndpointReal + testDraftEndpointReal call protected endpoints without session token (will always fail); testDraftEndpointReal subject assert uses `substr($draftId,-6)` vs saved `uniqid()` subject (can never pass)
- testDatabaseSchema "idempotency" is a false pass (static $done in initDatabase prevents re-run)
- SSRF only unit-tested; endpoints always run with EMMMAIL_DISABLE_SSRF_CHECK=1 inherited
- No MIME parser, MIME builder, or storeMessage/thread helper unit tests

## Session 7 (2026-08-26) — Security fixes
- Code review found 16 index.php issues + 8 test issues
- Phase 1 (Critical): Account takeover blocked (auth requires session to update existing accounts), IDOR fixed (all actions derive account_id from session)
- Phase 2 (High): CRLF injection prevented (sanitizeHeader), login rate limiting added, sessions bound to IP
- Phase 3 (Medium): IPv4-mapped IPv6 blocked in SSRF, try/finally for socket cleanup, encryption key race fixed with flock, max message size (10MB), Bcc removed from MIME headers, orphaned attachment cleanup
- Phase 4 (Low): Path containment on download, Content-Type sanitization, email validation, session cleanup
- Phase 5: Added 6 security tests (account takeover, IDOR, CRLF, rate limiting, IP binding, path containment)
- 207 tests passing, 0 failures, 3 skipped

## Session 8 (2026-08-26) — Core API endpoints
- Implemented 7 endpoints: list_folders, list_messages, get_message, get_thread, set_flags, batch_action, search
- Plan reviewed by 2 subagents; 25 findings addressed (FTS5 migration strategy, composite cursor pagination, FTS query escaping, folder role resolution, etc.)
- FTS5 migration via `migrateDatabase()` with `PRAGMA user_version` gating (separate from EMMMAIL_SCHEMA to avoid trigger body splitter issues)
- `escapeFtsQuery()` wraps user tokens in double quotes for literal FTS5 matching
- Composite cursor pagination: `(date, id)` keyset for list_messages
- set_flags/batch_action: flag-only changes are local-only (sync reconciles); moves/deletes use folder role resolution
- 290 tests passing, 0 failures, 3 skipped

## Key Decisions
- No ext-imap (deprecated) — raw socket IMAP client
- AES-256-GCM (not CBC) for password encryption
- FTS5 with triggers for search, migrated via `migrateDatabase()` (user_version 1→2)
- `normalizeSubject` strips prefix but preserves case
- TDD: tests FIRST, then implementation
- Session tokens via X-Session-Token header or EMMMAILSESSID cookie (not request body)
- Sessions bound to IP address (destroyed on IP change)
- EMMMAIL_DISABLE_SSRF_CHECK=1 for testing only
- Composite cursor pagination: `date|id` format, keyset `(date < ?) OR (date = ? AND id < ?)`
- FTS query escaping: wrap tokens in double quotes to prevent FTS5 syntax errors
- Folder role resolution: `SELECT name FROM folders WHERE account_id = ? AND role = ?`

## API Endpoints
- `auth` — test IMAP/SMTP, create/update account (public)
- `login` — authenticate, create session (public)
- `logout` — destroy session (authenticated)
- `session` — check session status (public)
- `sync` — fetch folders/messages from IMAP (authenticated)
- `list_folders` — list folders with unread/total counts (authenticated)
- `list_messages` — paginated message list per folder (authenticated)
- `get_message` — full message with body and attachments (authenticated)
- `get_thread` — thread metadata + all messages (authenticated)
- `set_flags` — mark read/starred, move to folder (authenticated)
- `batch_action` — bulk read/unread/star/unstar/delete/archive/move (authenticated)
- `search` — FTS5 full-text search (authenticated)
- `list_accounts` — list all accounts for switcher UI (authenticated)
- `add_account` — add new account, returns session token (authenticated)
- `delete_account` — delete current account + all data (authenticated)
- `detect_server` — auto-detect IMAP/SMTP from email domain (public)
- `test_connection` — test IMAP/SMTP without saving (public)
- `compose` — send email or save draft (authenticated)
- `drafts` — list drafts (authenticated)
- `draft` — get single draft (authenticated)
- `update_draft` — update draft (authenticated)
- `delete_draft` — delete draft (authenticated)
- `download_attachment` — download attachment file (authenticated)
- `message_attachments` — list message attachments (authenticated)
- `list_contacts` — list contacts by use count (authenticated)
- `search_contacts` — prefix-match contacts for autocomplete (authenticated)
- `list_tags` — list tags sorted by name (authenticated)
- `create_tag` — create tag with name and color (authenticated)

## Session 9 (2026-08-26) — Accounts endpoints
- Implemented 5 endpoints: list_accounts, add_account, delete_account, detect_server, test_connection
- Plan reviewed by 2 subagents; 18 findings addressed (IDOR fix, trust model, port/TLS validation, MX detection, etc.)
- Extracted `testImapSmtp()` helper from `actionAuth()` — shared by auth, add_account, test_connection
- Added `validatePortAndTls()` helper — port 1-65535, TLS 0-2
- Added `detectServersByEmail()` — domain→preset mapping + MX fallback with trailing-dot trimming
- `actionAuth()` refactored to use `testImapSmtp()` — same behavior, less duplication
- `detect_server`/`test_connection` added to `$publicActions` (setup wizard)
- `delete_account` uses transaction + `deleteAccountAttachments()` (was dead code)
- 332 tests passing, 0 failures, 3 skipped

## Session 10 (2026-08-26) — Contacts & Tags endpoints
- Implemented 4 endpoints: list_contacts, search_contacts, list_tags, create_tag
- Plan reviewed by 2 subagents; 21 findings addressed (name wipe fix, case-insensitive tags, LIKE escaping, etc.)
- Added 3 schema tables: contacts, tags, message_tags (in EMMMAIL_SCHEMA)
- `sanitizeContactName()` — strips control chars, collapses whitespace, caps at 200 chars
- `updateContacts()` — upsert hook in storeMessage, dedupes within message, preserves existing name
- `queryContacts()` — shared helper for list_contacts/search_contacts, LIKE wildcard escaping
- Tags use `COLLATE NOCASE` for case-insensitive uniqueness
- Color regex: `#[0-9a-fA-F]{3,4,6,8}` (valid CSS hex lengths only)
- Message tagging (tag_message/untag_message) deferred to future phase
- 382 tests passing, 0 failures, 3 skipped

## Session 11 (2026-08-26) — Settings endpoints
- Implemented 3 endpoints: get_settings, update_settings, update_signature
- Plan reviewed by 2 subagents; 17 findings addressed (per-account scoping, bool coercion, type safety, etc.)
- Added `settings` table: `(account_id, name, value)` with `PRIMARY KEY (account_id, name)`
- All settings per-account — different accounts have different preferences
- `getSettingsArray()` — whitelist-based, skips unknown keys, explicit bool coercion (`=== '1'`)
- `update_settings` — validates theme against `['dark', 'light']`, normalizes bools explicitly
- `update_signature` — requires key present, caps at 10000 chars (mb_strlen), returns error not silent truncation
- 415 tests passing, 0 failures, 3 skipped

## API Endpoints (complete)
- `auth` — test IMAP/SMTP, create/update account (public)
- `login` — authenticate, create session (public)
- `logout` — destroy session (authenticated)
- `session` — check session status (public)
- `sync` — fetch folders/messages from IMAP (authenticated)
- `list_folders` — list folders with unread/total counts (authenticated)
- `list_messages` — paginated message list per folder (authenticated)
- `get_message` — full message with body and attachments (authenticated)
- `get_thread` — thread metadata + all messages (authenticated)
- `set_flags` — mark read/starred, move to folder (authenticated)
- `batch_action` — bulk read/unread/star/unstar/delete/archive/move (authenticated)
- `search` — FTS5 full-text search (authenticated)
- `list_accounts` — list all accounts for switcher UI (authenticated)
- `add_account` — add new account, returns session token (authenticated)
- `delete_account` — delete current account + all data (authenticated)
- `detect_server` — auto-detect IMAP/SMTP from email domain (public)
- `test_connection` — test IMAP/SMTP without saving (public)
- `compose` — send email or save draft (authenticated)
- `drafts` — list drafts (authenticated)
- `draft` — get single draft (authenticated)
- `update_draft` — update draft (authenticated)
- `delete_draft` — delete draft (authenticated)
- `download_attachment` — download attachment file (authenticated)
- `message_attachments` — list message attachments (authenticated)
- `list_contacts` — list contacts by use count (authenticated)
- `search_contacts` — prefix-match contacts for autocomplete (authenticated)
- `list_tags` — list tags sorted by name (authenticated)
- `create_tag` — create tag with name and color (authenticated)
- `get_settings` — get per-account settings (authenticated)
- `update_settings` — update theme/bools (authenticated)
- `update_signature` — update email signature (authenticated)

## Session 12 (2026-08-26) — Missing endpoints for frontend
- Implemented 9 endpoints: ping, setup, rename_tag, delete_tag, tag_message, untag_message, batch_tag, expunge_folder, upload_attachment
- `ping` — public health check (version + time)
- `setup` — public check if first-time setup needed (no accounts)
- `rename_tag` — rename tag (account-scoped, checks existence)
- `delete_tag` — delete tag (account-scoped, checks existence)
- `tag_message` — add tag to message (INSERT OR IGNORE, idempotent)
- `untag_message` — remove tag from message
- `batch_tag` — batch tag/untag multiple messages (transaction)
- `expunge_folder` — IMAP EXPUNGE (connects to real server)
- `upload_attachment` — upload file for compose (multipart/form-data)
- 467 tests passing, 0 failures, 3 skipped

## Session 13 (2026-08-26) — Self-update endpoints
- Implemented 2 endpoints: check_update, apply_update
- `check_update` — returns current/latest version, update_available flag, update_url from settings
- `apply_update` — downloads new code from update_url, validates PHP syntax, checks for EMMMAIL_VERSION marker, backs up current file, replaces it
- `apply_update` reads `update_url` from per-account settings
- 476 tests passing, 0 failures, 3 skipped

## API Endpoints (complete — 42 endpoints)
- `ping` — health check (public)
- `setup` — check if first-time setup needed (public)
- `auth` — test IMAP/SMTP, create/update account (public)
- `login` — authenticate, create session (public)
- `logout` — destroy session (authenticated)
- `session` — check session status (public)
- `sync` — fetch folders/messages from IMAP (authenticated)
- `list_folders` — list folders with unread/total counts (authenticated)
- `list_messages` — paginated message list per folder (authenticated)
- `get_message` — full message with body and attachments (authenticated)
- `get_thread` — thread metadata + all messages (authenticated)
- `set_flags` — mark read/starred, move to folder (authenticated)
- `batch_action` — bulk read/unread/star/unstar/delete/archive/move (authenticated)
- `search` — FTS5 full-text search (authenticated)
- `list_accounts` — list all accounts for switcher UI (authenticated)
- `add_account` — add new account, returns session token (authenticated)
- `delete_account` — delete current account + all data (authenticated)
- `detect_server` — auto-detect IMAP/SMTP from email domain (public)
- `test_connection` — test IMAP/SMTP without saving (public)
- `compose` — send email or save draft (authenticated)
- `drafts` — list drafts (authenticated)
- `draft` — get single draft (authenticated)
- `update_draft` — update draft (authenticated)
- `delete_draft` — delete draft (authenticated)
- `download_attachment` — download attachment file (authenticated)
- `upload_attachment` — upload file for compose (authenticated)
- `message_attachments` — list message attachments (authenticated)
- `list_contacts` — list contacts by use count (authenticated)
- `search_contacts` — prefix-match contacts for autocomplete (authenticated)
- `list_tags` — list tags sorted by name (authenticated)
- `create_tag` — create tag with name and color (authenticated)
- `rename_tag` — rename tag (authenticated)
- `delete_tag` — delete tag (authenticated)
- `tag_message` — add tag to message (authenticated)
- `untag_message` — remove tag from message (authenticated)
- `batch_tag` — batch tag/untag messages (authenticated)
- `expunge_folder` — IMAP EXPUNGE (authenticated)
- `get_settings` — get per-account settings (authenticated)
- `update_settings` — update theme/bools (authenticated)
- `update_signature` — update email signature (authenticated)
- `check_update` — check for new version (authenticated)
- `apply_update` — download and apply update (authenticated)

## Next
- Build UI/UX
