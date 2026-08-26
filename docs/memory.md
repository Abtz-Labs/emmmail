# Emmmail — Session Memory

## Project Overview
- **Goal:** One-file, mobile-first, Gmail-inspired web email client with IMAP/SMTP
- **Tech:** PHP 8.1+, pure PHP IMAP/SMTP (stream_socket_client), Vanilla JS, SQLite
- **Auth:** IMAP-only login (user's credentials validated against IMAP server)
- **Philosophy:** Single `index.php`, zero deps, TDD

## Session 5 (2026-08-25) — Rewrite: API-first
- Started from scratch (old files renamed to `*.old`)
- API: `index.php?action=<action>`, JSON POST body
- Phase 1: `auth` endpoint — tests IMAP + SMTP connection, persists account to SQLite
- Infrastructure: `.env` parser, AES-256-GCM encryption, SQLite schema (accounts, login_attempts), IMAP/SMTP clients, utility functions
- Front controller guard: `if (php_sapi_name() !== 'cli' || isset($_GET['action']))` — prevents `exit` during test bootstrap
- `apiRequest()` test helper reads from exec stdout (not file) since `exit` kills subprocess before file write
- 82 tests passing: .env parser, encryption, DB schema, IMAP role detection/tokenizer/fetch parser, auth endpoint, unknown action, HTML escaping, display names, address parsing, filename sanitization, date normalization, subject normalization, blocked attachments, rate limiting

## Key Decisions
- No ext-imap (deprecated) — raw socket IMAP client
- AES-256-GCM (not CBC) for password encryption
- FTS5 with triggers for search (when needed)
- `normalizeSubject` strips prefix but preserves case
- TDD: tests FIRST, then implementation

## Session 6 (2026-08-26) — tests.php code review
- Full review of tests.php against index.php; suite passes 197/0/3 by default
- Latent bugs (only trigger when EMMMAIL_TEST_* set): testSyncEndpointReal + testDraftEndpointReal call protected endpoints without session token (will always fail); testDraftEndpointReal subject assert uses `substr($draftId,-6)` vs saved `uniqid()` subject (can never pass)
- testDatabaseSchema "idempotency" is a false pass (static $done in initDatabase prevents re-run)
- SSRF only unit-tested; endpoints always run with EMMMAIL_DISABLE_SSRF_CHECK=1 inherited
- No MIME parser, MIME builder, or storeMessage/thread helper unit tests

## Next
- Add more API endpoints (auth_status, list_accounts, messages, etc.)
- Build UI/UX
