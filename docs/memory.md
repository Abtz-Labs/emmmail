# Emmmail — Session Memory

**This file is a pointer sheet, not a knowledge base.** Durable, topic-scoped invariants live
in `docs/knowledge/` — start at [docs/knowledge/README.md](knowledge/README.md). Keep that
knowledge **out** of this file; add it there and link it from here. Budget: 150 lines.

## Project
- One-file Gmail-style webmail (IMAP/SMTP), mobile-first. PHP 8.1+, ext-imap, raw stream
  sockets for SMTP, vanilla JS, SQLite (PDO). Single `index.php`, routes via `?page=<page>`
  (full list in `README.md`; unknown -> 404, empty -> inbox/login).
- DB `emmmail.sqlite` (`EMMMAIL_DB_PATH`), forward-only `PRAGMA user_version`
  (  `SCHEMA_VERSION`). 8 tables: settings, contacts, search_cache (unused), login_attempts,
  messages (+ `thread_key`, v4), folder_state, folder_list_cache, folder_counts (v3). Bodies are never stored;
  headers are cached and `read_mark_seen()` keeps a 500-char snippet. The cache survives
  logout by design.
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
| Markdown, threading, drafts, undo send | [knowledge/composing.md](knowledge/composing.md) |
| imap ext install, CA bundle, Migadu hosts | [knowledge/environment.md](knowledge/environment.md) |

## Decisions in force
- Password only in the session, libsodium secretbox, key server-global
  (`EMMMAIL_ENCRYPTION_KEY`). One CSRF token per request, shared by all forms
<<<<<<< Updated upstream
  (`csrf_page_token()`). Login is a preset dropdown only, default `migadu`, unless the env
  locks it (see [knowledge/security.md](knowledge/security.md)). No jQuery, no CDN.
=======
  (`csrf_page_token()`). Login is a preset dropdown only, default `migadu`; deployment can pin
  it and whitelist domains with `EMMMAIL_FORCE_PRESET` / `EMMMAIL_ALLOWED_DOMAINS`. No jQuery, no CDN.
>>>>>>> Stashed changes
- **The CSP is `script-src 'self' 'unsafe-inline'`** (render_page emits an inline `<script>`),
  so it is **not** a script barrier. Never treat it as one — see
  [knowledge/security.md](knowledge/security.md).
- **Folder names are `urlencode()`d into every href and hidden input**, and every handler reads
  them with `urldecode()`. Both layers are required; never remove one without the other.
- Zero-dep `$` toolkit on `window.$`; **no `getElementById`, no per-page inline `<script>`, no
  native `confirm()`** — all three test-guarded. All `js_inline()` code stays stub-guarded.
- Perf shape: `folder_view_data()` takes an IMAP **factory**, so nothing connects unless the
  cache cannot answer. The header cache took a render from 52 IMAP commands (~2310ms) to 0
  (~30ms warm).

## Plan status
<<<<<<< Updated upstream
- **Code complete, browser pass NOT run:** `2026-10-19-server-lock-and-allowed-domains.md`.
  Env server lock + domain allow-list, fail closed.
=======
- **Code complete, deploy not run:** `2026-10-22-deploy-and-login-lock.md` — env-driven login
  lock (`EMMMAIL_ALLOWED_DOMAINS`, `EMMMAIL_FORCE_PRESET`), `bare.config.json` for
  `webmail.abtz.co` (git-ignored, DB in `private/`), dry-run passes; suite has one pre-existing
  footer failure (`&copy;` vs the test's `(c)`).
>>>>>>> Stashed changes
- **Done, manually verified:** `2026-10-06-footer.md` — one `view_footer()` credit line
  (author + Use Bare links, `utm_content=APP_VERSION`, `#<version> &copy; Abtz Labs`, both
  links `target="_blank" rel="noopener noreferrer"`), mounted in `.login-footer` (centered,
  outside `.login-box`) and `.sidebar-footer` (pinned with `margin-top:auto`, left aligned).
  `just test` 3024 passed, 2 smoke skips. Browser pass: login at 414px; sidebar measured at
  264px with the footer 16px off the floor. Light theme not seen.
- **Code complete, browser pass NOT run:** `2026-10-05-threaded-conversations.md` — all 11
  steps done, `just test` 2997 passed, 2 smoke skips. Schema v4 clears the `messages` cache
  once (pre-threading rows carried no `References`) and adds `thread_key` (root = first
  `References` token, else `In-Reply-To`, else own `Message-ID`; sentinel for id-less mail).
  `sync_folder()` computes it; `overviewRange()`/`fetchOverviews()` export the ids, and
  c-client already fetches `References` inside the same overview FETCH (the stub now answers
  `HEADER.FIELDS`). Listings group via `db_list_threads()` **only when the whole folder is
  cached** (`total <= cached`, not Drafts) — a partial window would double-show against the
  flat live tail. New `?page=thread` reader (`THREAD_MAX_MESSAGES`=30, one move dialog,
  active-`<details>` seam for the keyboard/`readerControl`). Cross-folder works because a
  Sent reply shares the root; `handle_sync()` keeps Sent warm. Thread rows: read toggle posts
  `thread=<key>`, the box picks all in-folder members. Fixed a shipped bug: rows cached
  between the v4 migration and the extractor kept `thread_key=''` and all grouped into one
  conversation; `db_count_unthreaded()` now makes `sync_decide()` purge such a folder once
  (reason `unthreaded`), and `folder_view_data()` checks it so the rebuild happens on the
  render. Browser pass owed: keyboard scoping, open/close iframe re-measure, count chip in
  both themes at phone width, a live pre-v4 cache upgrade, and a real Sent reply joining an
  Inbox thread.
- **Code complete, browser pass NOT run:** `2026-10-02-bulk-actions.md` — steps 0-7 done.
  Bulk mark read/unread, move, delete on the list view: `post_uids()` (three holes closed,
  200 cap, order preserving), four `MailImap::*Many*` batch methods returning **int**, one
  IMAP command per batch, `.mail-pick` + select-all + `.bulk-bar`, optimistic patching with
  an exact undo, `x` to pick the cursor row, and `pickCount()` in `syncCanSwap()`. The four
  batch return types are test-guarded because a `bool` there silently skips every cache
  write (`if ($count > 0)` against `true` is false). The delete confirm copy now shares the
  handler's resolver (`MailImap::findTrashFolderIn()` via `delete_is_permanent()`), so it
  warns "permanently" when no Trash is resolvable instead of promising a move. `just test`
  2790 passed, 2 smoke skips. Manual pass still owed: the picked tint on an unread row under
  the pointer, `.bulk-bar` in both themes, `j`/`x` right after clicking a checkbox, and the
  nested-`Deleted Items` account, whose copy should now read "permanently".
- **Code complete, live pass NOT run:** `2026-10-05-bulk-selection-polish.md` — round
  checkboxes, a "Select all" label, Space = `x` when a cursor exists, and the bulk bar
  moved into the mailbox head with `@container` tiers so it never moves the list; the
  select-all label doubles as the count ("18 selected"); on touch an idle row shows no picker or
  actions (tap the avatar to start a selection); fixed bulk mark read/unread
  removing its rows, the head dash surviving `pickReset()`, and the
  paperclip repainted as an envelope by `setReadIcon()`; bulk read/unread now drops the selection
  with the repaint, not the reply; `pickReset()` unchecks the row boxes too
  (measured at 12 widths, see [knowledge/css-ui.md](knowledge/css-ui.md)). `just test`
  2845 passed, 2 smoke skips.
- **Code complete, browser pass NOT run:** `2026-10-05-stale-listing-immediate-sync.md` —
  fixed the report that a fresh login showed "Synced 6 days ago" and did not sync: the header
  cache survives logout and `folder_view_data()` only syncs a cold cache, while the poll
  chain's first tick is a full period away. `view_mailbox()` now stamps `data-sync-stale="1"`
  on the `.sync` chip via the pure `sync_is_stale()`, and the client syncs a stale listing at
  boot (then the normal 60s chain); a fresh chip still waits its period. `just test` 2856
  passed, 2 smoke skips. Browser pass owed: log in with a >1min cache and watch it correct on
  load.
- **Done, manually verified:** `2026-10-02-solid-toast-and-login-memory.md` — toasts are
  opaque (`--surface` on `--text`, the type on the border; `--success` on `--surface` is only
  3.7:1 at `.88rem`, so the text stays uncoloured), and the login page remembers
  `emmmail-last-preset` + `emmmail-last-custom` beside the email. The memory is client-side
  because the server has no email to look settings up by before one is typed. `just test` 2558
  passed, 2 smoke skips. Browser pass: restore after reload, a failed submit saving all six
  values, and both themes' toast colours.
- **Done, not manually verified:** `2026-10-02-empty-folder-sidebar.md` — the folder list
  cache now has two readers: the listings honour the 15 min TTL and re-LIST, every other page
  (`cached_folder_list()`) serves the last copy whatever its age and never connects. Plus
  `db_set_folder_list()` refusing an unencodable payload, `kb_folder_map()` always mapping
  `i` => `INBOX`, and `view_sidebar()` rendering a `Folders are unavailable` note.
  `just test` 2528 passed, 2 smoke skips. The browser pass is not done: the sidebar on
  Settings after 15+ minutes of inbox idleness, and the note's look in light and dark.
- **Done:** `2026-09-29-browser-notifications.md` (poll chain, title counter, opt-in
  notifications, `auto_poll` switch replacing the *Sync period* select, settings rows with
  dividers and a right-hand control column). `just test` 2512 passed, 2 smoke skips. Manual pass
  by the user in Brave/macOS; Firefox, Safari, plain HTTP and idle stop not reported separately.
  macOS Focus / Do Not Disturb hides banners (they land in Notification Center).
- **Deferred manual passes** — code done, browser check not run, because no harness can stand in
  for a real Safari/Firefox/mobile keyboard: markdown-compose step 8 (editor verified in
  Chromium only); keyboard-first-ux step 7 (cursor/ring, real `showModal` focus, native
  `<select>` Enter in the move dialog, pagination cursor restore, undo-send Cmd+Enter,
  login/touch inertness); instant-folder-switch click-through (needs IMAP creds).
- Everything else is done. Keep each plan's `status:` header honest.

## Open items
- Open decision: drop the unused `search_cache` table (one migration step + 3 dead helpers).
  Destructive, so it needs an explicit yes.
- Known, unfixed, low priority: server-side-search pagination drops `server=1`; per-row output
  boilerplate is ~660 of 1485 bytes; `MailImap::__construct` pays a wasted TCP probe (~40ms);
  `handle_read`/`reply`/`forward` ignore `selectFolder()`'s result (read-only, so it degrades to
  "message not found"); cron-driven sync needs a credential story; nested `INBOX.Deleted Items`
  is not recognised as Trash anywhere (pinned by a test; the delete copy now warns anyway).
- Known, unfixed, from the 2026-10-02 plan: right after **Clear local cache** the sidebar is
  empty until an inbox visit — `g i` still works, the other five roles do not. And a server
  that sends a non-UTF-8 folder name makes every folder-list write fail, so the listings
  re-LIST each visit. Both are documented in
  [plans/2026-10-02-empty-folder-sidebar.md](plans/2026-10-02-empty-folder-sidebar.md).
