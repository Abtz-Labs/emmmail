# Emmmail — Session Memory

## Project Overview
- **Goal:** One-file, mobile-first, Gmail-inspired web email client with IMAP/SMTP
- **Philosophy:** Same as Tasssks (6620 LOC) and Uptime (3361 LOC) — single `index.php`, zero deps, SQLite
- **Tech:** PHP 8.1+, pure PHP IMAP/SMTP (stream_socket_client), Vanilla JS, CSS custom properties
- **Auth:** IMAP-only login (email credentials validated against IMAP server)

## Session 1 (2026-08-24) — Architecture Planning
- Explored Tasssks, Uptime, and Quickmail reference projects
- Drafted initial architecture plan
- Launched 3 review subagents (Security, Architecture, UX)
- Security: found 5 CRITICAL issues (auth model, key storage, sync route XSS, HTML email XSS, brute-force)
- Architecture: found 4 HIGH issues (schema bug, no authz model, Gmail incompatibility, missing routes)
- UX: found 5 HIGH issues (multi-account login, send-cron coupling, incomplete shortcuts, no multi-select, no mobile nav)
- Updated plan to address ALL findings:
  - Auth: IMAP-only (user's choice)
  - Encryption: AES-256-GCM, key in config file outside DB
  - Sync: CLI-only gate
  - HTML email: sandboxed iframe, no allow-scripts
  - Schema: fixed threads table, added indexes, login_attempts, FTS triggers
  - Send: JS-driven on countdown expiry, cron as fallback
  - Shortcuts: complete Gmail set + inInput guard
  - Multi-select: checkboxes + batch actions
  - Mobile: bottom tab bar, breakpoints, safe areas
  - Setup: provider presets with MX auto-detect
- Estimated ~7600 LOC (fits one-file philosophy)
- Plan at: `docs/plans/2026-08-24-emmmail-architecture.md`

## Session 2 (2026-08-24) — Frontend built
- Wrote complete frontend to `serveFrontend.php` (`serveFrontend()` returns the full HTML doc)
- Self-serving: `php serveFrontend.php` (or `php -S` + browse) outputs the page directly
- Contents: ~136KB; HTML skeleton (topbar, sidebar, 2 panes, compose overlay, modal, toast, bottom tabs, fullscreen auth), ~800-line CSS (dark default + light via `[data-theme]`, 3 breakpoints: 1024px 3-pane / 641-1023 2-pane / 640 mobile + bottom tabs), ~2050-line JS (`App` object)
- Verified: `php -l` clean, `node --check` clean, served HTML matches local file, tags balanced
- API contract (frontend → backend `index.php?action=`): auth_status/login/setup/logout, list_accounts, get_settings/update_signature/update_settings, list_messages(folder|tag_id), get_thread, get_message, set_flags, batch_action, batch_flags, batch_tag, schedule_send, cancel_send, process_outbox, save_draft, list_drafts, delete_draft, search, list_contacts, search_contacts, list_tags/create_tag, sync_account, detect_server, test_connection, add_account, delete_account, upload_attachment, get_attachment. Responses: `{ok:true,data}` or `{ok:false,error}`; `data` unwrapped by `App.api()`.
- Send flow: schedule_send → 10s countdown toast with Undo → process_outbox on expiry
- HTML email: sandboxed iframe (allow-same-origin, no scripts), sanitizer strips script/iframe/on*/javascript:, remote images blocked until "Show images"

## Session 3 (2026-08-24) — Backend + single-file index.php
- Built complete `index.php` (6249 LOC): backend + `serveFrontend()` (copied verbatim from serveFrontend.php lines 7–2927) + front controller
- Backend: AES-256-GCM password encryption (key in `.env`), full SQLite schema (12 tables + FTS5 with triggers, WAL), pure-PHP `ImapClient`/`SmtpClient` (stream_socket_client, TLS verify, literal-aware reader, dot-stuffing), MIME parser (multipart, base64/QP, RFC 2047, charset, attachments), all API endpoints, `cliSync()` with per-account lock + 60s budget
- Verified `php -l` clean; tested against a local Python IMAP server (sync/MIME/threading/contacts/attachments/flag push all correct)
- Endpoints smoke-tested end-to-end; rate limiting (5/acct, 15/IP per 15min), CSRF, session hardening verified
- Note: `publicRoutes` = `['', auth_login, auth_setup, auth_status, setup, ping]` — added `''` + `auth_status` (page/status must load pre-auth)
- Test artifacts (emmmail.sqlite, .env, attachments/) cleaned; project left in clean first-run state

## Session 4 (2026-08-24) — Test suite + TDD catch-up
- TDD catch-up: wrote `tests.php` (182 assertions, 30 groups, all passing)
- Self-contained: `php tests.php`, no external test framework, color output
- Uses `test.sqlite` DB + fixed test encryption key via env vars
- Tests: .env parser, AES-256-GCM round-trip, schema creation, IMAP role detection/tokenizer/fetch parser, MIME parser (simple/multipart/nested/attachments/charset), headers, boundary extraction, transfer encoding, RFC 2047/2231, address parsing, filename sanitization, date normalization, subject normalization, display names, HTML escaping, blocked attachments, header encoding, recipient sanitization, address list formatting, FTS5 search, account CRUD, message+thread store/aggregation, contacts upsert, tags, drafts, outbox status transitions, rate limiting
- Private IMAP methods tested via ReflectionMethod
- Rate-limit tests run in subprocess (fail() calls exit)
- Fixed Justfile: `test.php` → `tests.php`
- Updated architecture plan: marked "Test suite" done

## Key Decisions
- No ext-imap dependency (deprecated, unreliable) — raw socket IMAP client
- No OAuth2 for now (IMAP-only auth with app passwords for Gmail/Outlook)
- Tags instead of folders (virtual labels)
- 10-second debounced send with undo
- Provider presets: Gmail, Outlook, iCloud, Yahoo, Custom (MX auto-detect)
- AES-256-GCM (not CBC) for password encryption
- FTS5 with triggers (not external content) for search
- TDD: tests written after implementation (catch-up), going forward tests FIRST
