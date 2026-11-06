# Emmmail — Session Memory

**This file is a pointer sheet, not a knowledge base.** Durable, topic-scoped invariants live
in `docs/knowledge/` — start at [docs/knowledge/README.md](knowledge/README.md). Keep that
knowledge **out** of this file; add it there and link it from here. Budget: 150 lines.

## Project
- One-file Gmail-style webmail (IMAP/SMTP), mobile-first. PHP 8.1+, ext-imap, raw stream
  sockets for SMTP, vanilla JS, SQLite (PDO). Single `index.php`, routes via `?page=<page>`
  (full list in `README.md`; unknown -> 404, empty -> inbox/login).
- DB `emmmail.sqlite` (`EMMMAIL_DB_PATH`), forward-only `PRAGMA user_version`
  (  `SCHEMA_VERSION`). 9 tables: settings, contacts, search_cache (unused), login_attempts,
  messages (+ `thread_key`, v4), message_bodies (v5), folder_state, folder_list_cache,
  folder_counts (v3). Headers are cached; bodies are cached **encrypted** under a key derived
  from the mail password at login (see [knowledge/imap.md](knowledge/imap.md) and
  [knowledge/security.md](knowledge/security.md)); `read_mark_seen()` keeps a 500-char snippet.
  The cache survives logout by design (bodies as opaque blobs).
- TDD, zero deps. `just test` == `php tests/run.php`; suites `unit js imap smoke`; one with
  `php tests/<name>.php`. **No subset runner** — `run.php` ignores `$argv`, so naming suites
  runs all four. `_`-prefixed files are support, not suites. `smoke` is live (skips w/o
  creds), `js` skips w/o `node`.

## Knowledge map
| Area | Read |
|---|---|
| IMAP/c-client traps, sync, folder counts | [knowledge/imap.md](knowledge/imap.md) |
| PHP/HTML gotchas, includes, redirect | [knowledge/php-html.md](knowledge/php-html.md) |
| Sanitizers, iframe sandbox, remote images, gates | [knowledge/security.md](knowledge/security.md) |
| The `$` toolkit, busy states, optimistic actions, the poll chain | [knowledge/frontend.md](knowledge/frontend.md) |
| Tokens, specificity, tooltips, toolbars, composer | [knowledge/css-ui.md](knowledge/css-ui.md) |
| TDD loop, source guards, the js stub | [knowledge/testing.md](knowledge/testing.md) |
| Markdown, threading, send-as, drafts, undo send | [knowledge/composing.md](knowledge/composing.md) |
| imap ext install, CA bundle, Migadu hosts and send-as identities | [knowledge/environment.md](knowledge/environment.md) |

## Decisions in force
- Password only in the session, libsodium secretbox, key server-global
  (`EMMMAIL_ENCRYPTION_KEY`). One CSRF token per request, shared by all forms
  (`csrf_page_token()`). Login is a preset dropdown only, default `migadu`, unless the env
  locks it (see [knowledge/security.md](knowledge/security.md)). No jQuery, no CDN.
- **The CSP is `script-src 'self' 'unsafe-inline'`** (render_page emits an inline `<script>`),
  so it is **not** a script barrier. Never treat it as one — see
  [knowledge/security.md](knowledge/security.md).
- **Folder names are `urlencode()`d into every href and hidden input**, and every handler reads
  them with `urldecode()`. Both layers are required; never remove one without the other.
- Zero-dep `$` toolkit on `window.$`; **no `getElementById`, no per-page inline `<script>`, no
  native `confirm()`** — all three test-guarded. All `js_inline()` code stays stub-guarded.
- Perf shape: `folder_view_data()` takes an IMAP **factory**, so nothing connects unless the
  cache cannot answer. The header cache took a render from 52 IMAP commands (~2310ms) to 0
  (~30ms warm). `message_read_through()` does the same for the reader: a cached, already-read
  message opens with **no connection**; a miss is `SELECT` + 3 FETCH-class commands. The
  stylesheet and script are immutable-cached routes (`?page=asset`), not inlined.

## Plan status
Details, decisions and owed checks live in each plan; keep its `status:` header honest.

**Production:** `0.2.0`, deployed 2026-11-06. `bare deploy` bumps and the pre-script
commits/pushes the version; the Migadu login lock is verified (no provider picker).

**Code complete, browser pass NOT run** (what is owed, in brief):
- `2026-11-06-read-and-sync-performance.md` — encrypted body cache + prefetch, one-body
  conversations with lazy members, 3-command reads, direct-folder open, 5-min reconcile,
  in-place post-sync refresh, `?page=asset`. Owed: the whole browser pass (see the plan),
  especially an expanding collapsed member, Show images from one, and a login after deploy
  (old sessions have no `body_key`, so they run without the body cache until re-login).
- `2026-11-05-provider-storage-usage.md` — Settings storage card: RFC 2087 QUOTA, or
  cached-message estimate. Deployed in `0.2.0`. Owed: Gmail live, a non-QUOTA server,
  both themes, phone width.
- `2026-11-05-undo-send-closes-composer.md` — plain-POST Send to Sent, undo bar on every page
  (says logout cancels), Undo via Drafts, `compose_id` send-once. Owed: page moves, Undo with
  attachment / no Drafts, closed tab, Back + Send, phone width.
- `2026-11-05-per-identity-signatures.md` — `send_as_signatures`, From swap. Owed: Chrome +
  Firefox Enter-at-signature, forward, draft.
- `2026-11-05-send-as-aliases.md` — Send-as list, From picker, reply auto-alias.
- `2026-11-04-selection-and-navigation-fixes.md`, `-composer-autocomplete-keyboard.md`,
  `-list-spam-button.md`.
- `2026-10-24-favicon.md`, `2026-10-24-message-links-new-tab.md`.
- `2026-10-19-server-lock-and-allowed-domains.md` — env lock + allow-list, fail closed.
- `2026-10-05-threaded-conversations.md` — owed: keyboard scoping, iframe re-measure, count chip
  at phone width, live pre-v4 upgrade, a Sent reply joining an Inbox thread.
- `2026-10-05-stale-listing-immediate-sync.md` — owed: login with a >1 min cache.
- `2026-10-05-bulk-selection-polish.md`, `2026-10-02-bulk-actions.md` — owed: picked tint under
  the pointer, `.bulk-bar` both themes, `j`/`x` after a checkbox click, nested `Deleted Items`.
- `2026-10-02-empty-folder-sidebar.md` — owed: Settings sidebar after 15+ min idle, note in
  both themes.
- Deferred (no harness can do it): markdown-compose step 8 (only Chromium seen),
  keyboard-first-ux step 7, instant-folder-switch click-through (needs IMAP creds).

**Done, manually verified:** `2026-10-22-deploy-and-login-lock.md` (0.2.0, login lock
verified without the provider picker), `2026-10-06-footer.md` (light theme not seen),
`2026-10-02-solid-toast-and-login-memory.md`, `2026-09-29-browser-notifications.md`
(Brave/macOS only). Everything else is done.

## Open items
- Open decision: drop the unused `search_cache` table (one migration + 3 dead helpers). Destructive; needs an explicit yes.
- Known, unfixed, low priority: server-side-search pagination drops `server=1`; per-row output
  boilerplate is ~660 of 1485 bytes; `MailImap::__construct` pays a wasted TCP probe (~40ms);
  `reply`/`forward` ignore `selectFolder()`'s result (read-only, so it degrades to
  "message not found"); cron-driven sync needs a credential story; nested `INBOX.Deleted Items`
  is not recognised as Trash anywhere (pinned by a test; the delete copy now warns anyway).
- Known, by design: a body cached display-only (one text part) serves reply/forward from its
  HTML via `strip_html_for_quote()`, like an HTML-only message; the `\Seen` STORE after a
  cache hit waits on the response only where `fastcgi_finish_request()` exists (php -S has
  none). Prefetch is INBOX/current/Sent only — whatever the poll syncs.
- Known, unfixed (see `2026-10-02-empty-folder-sidebar.md`): after **Clear local cache** the
  sidebar is empty until an inbox visit; a non-UTF-8 folder name makes every folder-list
  write fail, so listings re-LIST each visit.
- Known, unfixed: Undo opens a draft, which overwrites `forward_src`; a forward composer open
  in another tab then attaches the draft's parts.
- **Fixed (browser pass NOT run):** empty Spam/Trash under a count (`thread_folder_is_member()`).
