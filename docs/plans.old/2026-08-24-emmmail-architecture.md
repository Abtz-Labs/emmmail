# Emmmail — Architecture Plan

**Status:** pending
**Date:** 2026-08-24
**Goal:** One-file, mobile-first, Gmail-inspired web email client with IMAP/SMTP support
**Reviews addressed:** Security (5 CRITICAL, 8 MEDIUM), Architecture (4 HIGH, 8 MEDIUM), UX (5 HIGH, 6 MEDIUM)

---

## 1. Core Principles

| Principle | Detail |
|---|---|
| **One file** | `index.php` — all backend, HTML, CSS, JS in a single file |
| **Zero deps** | No Composer, no npm, no CDN libraries (vanilla JS only) |
| **SQLite** | Local cache for messages, contacts, tags, drafts, accounts |
| **Pure PHP IMAP/SMTP** | Raw socket clients via `stream_socket_client` — no `ext-imap` |
| **Mobile-first** | Responsive design, touch-friendly, Gmail-inspired UI |
| **Cron sync** | `php index.php sync` runs background sync for all accounts |

---

## 2. Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8.1+ (single `index.php`) |
| Database | SQLite via PDO (WAL mode, foreign keys) |
| IMAP client | `stream_socket_client` (raw RFC 3501 protocol) |
| SMTP client | `stream_socket_client` (raw RFC 5321, STARTTLS/SSL, PLAIN/LOGIN auth) |
| MIME parsing | Pure PHP (base64, quoted_printable, mb_decode_mimeheader, iconv) |
| Frontend | Vanilla JS (no framework), CSS custom properties |
| Auth | IMAP-only login (credentials validated against IMAP server) |
| Encryption | AES-256-GCM for stored IMAP/SMTP passwords (`openssl_*`) |

**Runtime dependencies:** mbstring, openssl, SQLite3 with FTS5, stream extensions.

---

## 3. Database Schema

```sql
-- IMAP/SMTP encryption key (stored in config file outside DB, reference here for fingerprint)
-- Key is loaded from EMMMAIL_KEY env var or config/key.php file

-- Rate limiting
CREATE TABLE login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_login_attempts_email_ip ON login_attempts(email, ip);
CREATE INDEX idx_login_attempts_time ON login_attempts(attempted_at);

-- Email accounts
CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT,
    imap_host TEXT NOT NULL,
    imap_port INTEGER NOT NULL DEFAULT 993,
    imap_tls INTEGER NOT NULL DEFAULT 1,  -- 1=SSL, 2=STARTTLS, 0=none
    smtp_host TEXT NOT NULL,
    smtp_port INTEGER NOT NULL DEFAULT 587,
    smtp_tls INTEGER NOT NULL DEFAULT 2,  -- 1=SSL, 2=STARTTLS, 0=none
    password_encrypted TEXT NOT NULL,     -- AES-256-GCM encrypted IMAP password
    password_iv TEXT NOT NULL,            -- GCM nonce
    password_tag TEXT NOT NULL,           -- GCM auth tag
    is_active INTEGER NOT NULL DEFAULT 1,
    sync_enabled INTEGER NOT NULL DEFAULT 1,
    last_sync_at TEXT,
    sync_error TEXT,                      -- last sync error message
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

-- IMAP folders (cached for sync state)
CREATE TABLE folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    role TEXT,  -- inbox, sent, drafts, trash, junk, archive, null
    uid_validity INTEGER,
    last_uid INTEGER DEFAULT 0,
    unread_count INTEGER DEFAULT 0,
    total_count INTEGER DEFAULT 0,
    UNIQUE(account_id, name)
);
CREATE INDEX idx_folders_account ON folders(account_id);

-- Message threads (conversations)
CREATE TABLE threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    thread_key TEXT NOT NULL,              -- normalized subject hash or first Message-ID
    subject TEXT,
    last_message_at TEXT NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 0,
    preview TEXT,                          -- snippet of last message
    is_read INTEGER NOT NULL DEFAULT 0,
    is_starred INTEGER NOT NULL DEFAULT 0,
    UNIQUE(account_id, thread_key)
);
CREATE INDEX idx_threads_account_date ON threads(account_id, last_message_at DESC);

-- Cached messages
CREATE TABLE messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    folder_name TEXT NOT NULL,
    uid INTEGER NOT NULL,
    message_id TEXT,                       -- RFC 2822 Message-ID
    in_reply_to TEXT,
    refs TEXT,                             -- space-separated Message-IDs (renamed from "references" to avoid SQL keyword)
    subject TEXT,
    from_address TEXT,
    from_name TEXT,
    to_addresses TEXT,                     -- JSON array of {name, email}
    cc_addresses TEXT,
    date TEXT,                             -- stored as UTC ISO 8601
    text_body TEXT,
    html_body TEXT,
    thread_id INTEGER REFERENCES threads(id),
    is_read INTEGER NOT NULL DEFAULT 0,
    is_starred INTEGER NOT NULL DEFAULT 0,
    has_attachments INTEGER NOT NULL DEFAULT 0,
    size INTEGER,
    raw_headers TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, folder_name, uid)
);
CREATE INDEX idx_messages_thread ON messages(thread_id);
CREATE INDEX idx_messages_account_folder ON messages(account_id, folder_name);
CREATE INDEX idx_messages_account_read ON messages(account_id, is_read);
CREATE INDEX idx_messages_account_starred ON messages(account_id, is_starred);
CREATE INDEX idx_messages_account_date ON messages(account_id, date DESC);
CREATE INDEX idx_messages_message_id ON messages(message_id);

-- Full-text search (standalone table, not external content — avoids stale index issues)
CREATE VIRTUAL TABLE messages_fts USING fts5(
    subject, from_name, from_address, to_addresses, text_body,
    content=messages,
    content_rowid=id,
    tokenize='porter unicode61'
);
-- Triggers to keep FTS in sync
CREATE TRIGGER messages_ai AFTER INSERT ON messages BEGIN
    INSERT INTO messages_fts(rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES (new.id, new.subject, new.from_name, new.from_address, new.to_addresses, new.text_body);
END;
CREATE TRIGGER messages_ad AFTER DELETE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES ('delete', old.id, old.subject, old.from_name, old.from_address, old.to_addresses, old.text_body);
END;
CREATE TRIGGER messages_au AFTER UPDATE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES ('delete', old.id, old.subject, old.from_name, old.from_address, old.to_addresses, old.text_body);
    INSERT INTO messages_fts(rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES (new.id, new.subject, new.from_name, new.from_address, new.to_addresses, new.text_body);
END;

-- Attachment metadata (files stored in attachments/ directory)
CREATE TABLE attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    content_type TEXT,
    size INTEGER,
    file_path TEXT NOT NULL,  -- relative path: {account_id}/{message_id}/{sanitized_filename}
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_attachments_message ON attachments(message_id);

-- Auto-extracted contacts
CREATE TABLE contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    email TEXT NOT NULL,
    name TEXT,
    use_count INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, email)
);
CREATE INDEX idx_contacts_account ON contacts(account_id);
CREATE INDEX idx_contacts_account_email ON contacts(account_id, email);

-- User tags (virtual labels, not IMAP folders)
CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#6366f1',
    UNIQUE(account_id, name)
);
CREATE INDEX idx_tags_account ON tags(account_id);

-- Message-tag association
CREATE TABLE message_tags (
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (message_id, tag_id)
);
CREATE INDEX idx_message_tags_tag ON message_tags(tag_id);

-- Local drafts (not synced to IMAP server)
CREATE TABLE drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    to_addresses TEXT,                     -- JSON array
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject TEXT,
    body TEXT,
    html_body TEXT,
    in_reply_to_message_id TEXT,           -- Message-ID of message being replied to
    attachments_json TEXT,                 -- JSON array of {filename, content_type, size, tmp_path}
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_drafts_account ON drafts(account_id);

-- Debounced outbox (undo window before sending)
CREATE TABLE outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    from_address TEXT,
    to_addresses TEXT NOT NULL,            -- JSON array
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject TEXT,
    body TEXT,
    html_body TEXT,
    in_reply_to_message_id TEXT,
    attachments_json TEXT,
    send_at TEXT NOT NULL,                 -- datetime when email should be sent
    status TEXT NOT NULL DEFAULT 'pending', -- pending, sent, cancelled, failed
    error_message TEXT,                    -- SMTP error if failed
    retry_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX idx_outbox_status ON outbox(status, send_at);
CREATE INDEX idx_outbox_account ON outbox(account_id);

-- App settings
CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
```

---

## 4. Backend Architecture

### 4.1 Routing

Same front-controller pattern as Tasssks/Uptime, with explicit CSRF exemptions:

```php
// CLI detection for sync command
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'sync') {
    cliSync();
    exit;
}

$action = $_GET['action'] ?? '';

// Public routes (no auth, no CSRF)
$publicRoutes = ['auth_login', 'auth_setup', 'setup', 'ping'];

if (!in_array($action, $publicRoutes)) {
    requireAuth();  // 401 if no session
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verifyCsrf();  // 403 if CSRF mismatch
    }
}

match ($action) {
    // Public
    'ping'              => apiPing(),
    'auth_login'        => apiAuthLogin(),   // POST, rate-limited
    'auth_setup'        => apiAuthSetup(),   // POST, first-run only
    // Auth
    'auth_status'       => apiAuthStatus(),
    'auth_logout'       => apiAuthLogout(),
    // Accounts
    'list_accounts'     => apiListAccounts(),
    'add_account'       => apiAddAccount(),   // POST, rate-limited
    'delete_account'    => apiDeleteAccount(),
    'test_connection'   => apiTestConnection(), // POST, rate-limited
    'detect_server'     => apiDetectServer(),  // POST, MX-based auto-detect
    // Messages
    'list_messages'     => apiListMessages(),
    'get_message'       => apiGetMessage(),
    'sync_account'      => apiSyncAccount(),   // POST, manual sync trigger
    // Move/Delete/Expunge
    'move_message'      => apiMoveMessage(),
    'delete_message'    => apiDeleteMessage(),  // IMAP COPY to Trash + \Deleted flag
    'expunge_folder'    => apiExpungeFolder(),  // IMAP EXPUNGE
    'batch_action'      => apiBatchAction(),    // POST, multi-select operations
    // Compose
    'send_message'      => apiSendMessage(),
    'save_draft'        => apiSaveDraft(),
    'list_drafts'       => apiListDrafts(),
    'delete_draft'      => apiDeleteDraft(),
    // Outbox (debounced send)
    'schedule_send'     => apiScheduleSend(),
    'cancel_send'       => apiCancelSend(),
    'process_outbox'    => apiProcessOutbox(),  // POST, JS-triggered send on countdown expiry
    // Flags
    'set_flags'         => apiSetFlags(),
    'batch_flags'       => apiBatchFlags(),     // POST, multi-select flag operations
    // Tags
    'list_tags'         => apiListTags(),
    'create_tag'        => apiCreateTag(),
    'rename_tag'        => apiRenameTag(),
    'delete_tag'        => apiDeleteTag(),
    'tag_message'       => apiTagMessage(),
    'untag_message'     => apiUntagMessage(),
    'batch_tag'         => apiBatchTag(),       // POST, multi-select tag operations
    // Contacts
    'list_contacts'     => apiListContacts(),
    'search_contacts'   => apiSearchContacts(),
    // Search
    'search'            => apiSearch(),
    // Attachments
    'get_attachment'    => apiGetAttachment(),   // authenticated, verifies ownership
    'upload_attachment' => apiUploadAttachment(), // POST, for compose
    // Settings
    'get_settings'      => apiGetSettings(),
    'update_settings'   => apiUpdateSettings(),
    // Signature
    'get_signature'     => apiGetSignature(),
    'update_signature'  => apiUpdateSignature(),
    // Update
    'check_update'      => apiCheckUpdate(),
    'apply_update'      => apiApplyUpdate(),
    default             => serveFrontend(),
};
```

### 4.2 Pure PHP IMAP Client (~400 LOC)

- `ImapClient` class using `stream_socket_client`
- Methods: `connect()`, `login()`, `listFolders()`, `selectFolder()`, `statusFolder()`, `fetchMessages()`, `fetchMessage()`, `fetchFlags()`, `setFlags()`, `copyMessages()`, `expunge()`
- Supports: SSL (port 993), STARTTLS (port 143), PLAIN/LOGIN auth
- **TLS verification:** Always set `verify_peer => true`, `verify_peer_name => true`, `SNI_enabled => true`
- **Stream timeouts:** `stream_set_timeout($sock, 30)` per connection; per-account 60s budget
- Response parser for `* LIST`, `* FETCH`, `* SEARCH`, `* STATUS`
- UID-based incremental sync: store `uid_validity` + `last_uid` per folder
- **Flag re-sync:** Periodic `UID SEARCH UNSEEN` + `FETCH FLAGS` to catch external changes
- **Batch fetch:** `UID FETCH 1:* (FLAGS)` for flag sync; batch `BODY.PEEK[]` for multiple UIDs
- **Expunge reconciliation:** Detect server-side deletions via `UID SEARCH ALL` vs cached UIDs

### 4.3 Pure PHP SMTP Client (~200 LOC)

- `SmtpClient` class using `stream_socket_client`
- Methods: `connect()`, `login()`, `send()`
- Supports: SSL (port 465), STARTTLS (port 587), PLAIN/LOGIN auth
- **TLS verification:** Same as IMAP — `verify_peer`, `verify_peer_name`
- **Stream timeouts:** 30s per command
- MIME message builder: headers, multipart body, base64 attachments, dot-stuffing
- **Header injection prevention:** Strip `\r` and `\n` from all user-supplied header values (Subject, To, Cc, Bcc, From) before building MIME headers
- **BCC handling:** Exclude Bcc from message headers (only in envelope)

### 4.4 MIME Parser (~450 LOC)

- Parse raw RFC 2822 messages (from IMAP `FETCH BODY[]`)
- Handle: multipart/mixed, multipart/alternative, multipart/related
- Decode: base64, quoted-printable, 7bit, 8bit
- Extract: text/plain, text/html, attachments (inline and regular)
- Decode headers: RFC 2047 encoded words (=?UTF-8?B?...?=)
- Charset conversion via `mb_convert_encoding()` / `iconv()`
- **Date normalization:** Parse all date formats to UTC ISO 8601 at ingest

### 4.5 Sync Engine

```
cliSync() — CLI-only (PHP_SAPI === 'cli')
  Gated by: PHP_SAPI check + optional cron_token setting
For each active account (with 60s per-account time budget):
  1. Connect to IMAP, login
  2. LIST folders, upsert into `folders` table
  3. For each folder:
     a. SELECT folder, check UIDVALIDITY (reset sync state if changed, clear old messages)
     b. FETCH UIDs since last_uid
     c. Batch FETCH new messages (BODY.PEEK[] + FLAGS)
     d. Parse MIME, store message + attachments (sanitized filenames)
     e. Update last_uid, unread_count, total_count
  4. Flag re-sync: UID SEARCH UNSEEN → update is_read for all cached messages
  5. Expunge reconciliation: detect server-side deletions, mark or remove cached messages
  6. Build/update threads (see §4.6)
  7. Extract contacts from From/To/Cc headers
  8. Update FTS index (via triggers)
  9. Process outbox: send pending messages past their send_at time (with 5s lock to prevent double-send)
  10. Update last_sync_at, clear sync_error
```

**Per-account lock:** Use a SQLite advisory lock (`SELECT * FROM accounts WHERE id = ? FOR UPDATE` pattern via `BEGIN IMMEDIATE`) to prevent concurrent syncs on the same account.

### 4.6 Threading Algorithm (Gmail-style heuristic)

```
For each message:
  1. Compute thread_key:
     a. If In-Reply-To exists and matches a known message → use parent's thread_key
     b. Else if References exists → use the most recent referenced message's thread_key
     c. Else: normalize subject (strip Re:/Fwd:/etc., whitespace, locale prefixes), hash → use as thread_key
  2. Find or create thread by (account_id, thread_key)
  3. Add message to thread
  4. Update thread.last_message_at, thread.message_count, thread.preview, thread.is_read, thread.is_starred
  5. On late-arriving parent: re-thread children that referenced it
  6. Cycle guard: track visited Message-IDs during References walk
```

### 4.7 Password Encryption (AES-256-GCM)

```php
function getEncryptionKey(): string {
    // Priority: 1) EMMMAIL_KEY env var, 2) .env file, 3) generate and append to .env
    if ($key = getenv('EMMMAIL_KEY')) return $key;

    $envFile = __DIR__ . '/.env';
    $env = parseEnvFile($envFile);
    if (!empty($env['EMMMAIL_KEY'])) {
        putenv('EMMMAIL_KEY=' . $env['EMMMAIL_KEY']);
        return $env['EMMMAIL_KEY'];
    }

    // First run: generate 32-byte hex key, append to .env
    $key = sodium_bin2hex(random_bytes(32));
    file_put_contents($envFile, "EMMMAIL_KEY={$key}\n", FILE_APPEND | LOCK_EX);
    putenv('EMMMAIL_KEY=' . $key);
    return $key;
}

function parseEnvFile(string $path): array {
    $env = [];
    if (!file_exists($path)) return $env;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function encryptPassword(string $password, string $key): array {
    $iv = random_bytes(SODIUM_CRYPTO_AEAD_AES256GCM_NPUBBYTES); // 12 bytes
    $encrypted = sodium_crypto_aead_aes256gcm_encrypt($password, '', $iv, $key);
    return [
        'encrypted' => base64_encode($encrypted),
        'iv' => base64_encode($iv),
    ];
}

function decryptPassword(string $encrypted, string $iv, string $key): string {
    return sodium_crypto_aead_aes256gcm_decrypt(
        base64_decode($encrypted), '', base64_decode($iv), $key
    );
}
```

**Key management:** Encryption key stored in `.env` file as `EMMMAIL_KEY=64-char-hex` (gitignored, chmod 600). Can also be set via environment variable. Auto-generated on first run. Never in the database.

---

## 5. Frontend Architecture

### 5.1 Breakpoints & Layout

| Breakpoint | Layout |
|---|---|
| ≥1024px | Three-pane: sidebar (240px) + message list (360px) + message view (flex) |
| 641–1023px | Two-pane: message list + back-button detail view |
| ≤640px | Single-pane: full-width views, bottom tab bar, hamburger for sidebar |

**Mobile bottom tab bar:** Inbox / Search / Compose (FAB) / Settings — always visible, thumb-zone accessible.

**Safe areas:** `env(safe-area-inset-*)` padding for iOS notches. `viewport-fit=cover` in meta tag.

```
┌─────────────────────────────────────────────────┐
│  ☰  Emmmail           🔍  🔔  👤              │  ← Topbar
├────────┬────────────────────────────────────────┤
│        │  ┌──────────────────────────────────┐  │
│  Side  │  │  Thread list / Message view /    │  │
│  bar   │  │  Compose overlay                 │  │
│        │  └──────────────────────────────────┘  │
│        ├────────────────────────────────────────┤
│        │  📥  🔍  ✏️  ⚙️                      │  ← Mobile bottom tabs
└────────┴────────────────────────────────────────┘
```

### 5.2 Views

| View | Route | Description |
|---|---|---|
| Login | `#login` | Email + password form |
| Setup | `#setup` | First-run wizard (add first account) |
| Inbox | `#inbox` | Thread list for inbox |
| Sent | `#sent` | Sent messages |
| Drafts | `#drafts` | Local drafts |
| Starred | `#starred` | Starred messages |
| All | `#all` | All messages |
| Tag | `#tag/{id}` | Messages with specific tag |
| Search | `#search?q=...` | Search results |
| Thread | `#thread/{id}` | Full conversation view |
| Compose | `#compose` | New message (modal/overlay) |
| Reply | `#reply/{id}` | Reply to message |
| Forward | `#forward/{id}` | Forward message |
| Settings | `#settings` | Account management |
| Contacts | `#contacts` | Contact list |

### 5.3 Keyboard Shortcuts

All shortcuts are guarded by `inInput` check — no shortcuts fire when focus is in an input, textarea, or contenteditable element. Shortcuts also blocked when a modal is open (except Esc).

| Key | Action |
|---|---|
| `c` | Compose new message |
| `r` | Reply |
| `a` | Reply all |
| `f` | Forward |
| `e` | Archive (move to archive) |
| `#` | Delete (move to trash) |
| `s` | Star/unstar |
| `Shift+u` | Mark read/unread |
| `/` or `Cmd+k` | Focus search |
| `Esc` | Close compose/view, deselect |
| `j` / `k` | Next/previous message |
| `Enter` / `o` | Open message |
| `x` | Select/deselect message (multi-select mode) |
| `z` | Undo last action (archive/delete/tag) |
| `g` then `i` | Go to Inbox |
| `g` then `a` | Go to All Mail |
| `g` then `s` | Go to Sent |
| `g` then `d` | Go to Drafts |
| `Cmd+Enter` | Send message (from compose) |
| `?` | Show shortcuts help |

### 5.4 Multi-Select & Batch Actions

When user clicks checkbox or presses `x`:
1. Enable multi-select mode (checkboxes appear on all thread rows)
2. Show batch action bar at top: Archive / Delete / Star / Mark Read / Tag / Move
3. Select-all checkbox in header
4. Exit multi-select mode on Esc or after action completes
5. Show undo toast after batch action

### 5.5 UI Components (Vanilla JS)

- `App` — global state object (like Tasssks)
- `App.esc()` / `App.escAttr()` — XSS-safe escaping
- `renderSidebar()` — accounts, tags, navigation (visually grouped: Folders then Tags)
- `renderMessageList()` — thread list with avatars, preview, date, checkboxes
- `renderThread()` — conversation view with message stack, quoted-text collapse
- `renderCompose()` — floating compose window (contenteditable with minimal toolbar)
- `renderSearch()` — search results with advanced filters
- `renderSettings()` — account management with provider presets
- `renderContacts()` — contact list with search
- Modal system (reuse from Tasssks pattern)
- Toast notifications (bottom-center, with undo action)
- Dark/light theme (CSS custom properties, prefers-color-scheme + localStorage)
- Empty states (illustrated messages for empty inbox, no drafts, etc.)
- Loading skeletons (placeholder rows while loading)
- Offline/sync status indicator ("Last synced: 2 min ago" / "Sync error: ...")

### 5.6 HTML Email Rendering (Security)

All received HTML emails render in a **sandboxed iframe**:

```html
<iframe sandbox="allow-same-origin" srcdoc="..."></iframe>
```

- **No `allow-scripts`** — sender JavaScript cannot execute
- **No `allow-popups`** — links open in a new tab via the app (not the iframe)
- Remote images blocked by default (load on user click via proxy or show placeholder)
- User toggle: "Show images" per message or globally in settings
- Text/plain fallback always available
- CSS: `pointer-events: none` on iframe, app handles link clicks via `postMessage` or intercept

### 5.7 Compose Features

- **Recipient chips:** Autocomplete from contacts table as user types
- **Cc/Bcc toggle:** Expandable fields
- **From picker:** Dropdown of account addresses + verified aliases
- **Rich text:** `contenteditable` with minimal toolbar (bold, italic, link, list, quote) — no CDN deps
- **Attachments:** Drag-drop zone, file picker, size limit display (25MB total), progress indicator
- **Autosave:** Draft saved every 30 seconds or on close; "Draft saved" toast
- **Signature:** Auto-appended from account signature setting
- **Quoting:** On reply/forward, original message quoted with `>` prefix (plain) or blockquote (HTML)

### 5.8 Debounced Send Flow

```
1. User clicks "Send" (or Cmd+Enter)
2. Validate: all recipients valid, subject non-empty, attachment size ok
3. Strip header injection characters from all fields
4. Message stored in `outbox` with send_at = now + 10 seconds
5. UI shows toast: "Sending in 10s... [Undo]" with countdown
6. If user clicks "Undo" → DELETE from outbox, toast "Cancelled"
7. If countdown expires → JS fires POST /api/process_outbox immediately
   → SmtpClient sends the message
   → On success: IMAP COPY to Sent folder, DELETE from outbox
   → On failure: mark outbox row as 'failed', show error toast with retry button
8. Cron fallback: if JS didn't trigger (tab closed), cron picks up outbox items past send_at
9. Double-send prevention: UPDATE outbox SET status='sending' WHERE id=? AND status='pending' (atomic claim)
```

### 5.9 Account Switching

- **Session model:** Single session holds `active_account_id`; multiple accounts stored in DB
- **Login flow:** First login validates IMAP credentials, creates session. After login, user can add more accounts from Settings → Add Account (each validated against IMAP).
- **Account switcher:** Dropdown in topbar avatar menu, shows all accounts with unread badges
- **Combined inbox:** Optional "All Accounts" view that merges inbox threads from all accounts
- **Per-account sync status:** Show last sync time and errors per account in Settings
- **Logout:** Destroys session; does NOT delete stored credentials (user can log back in)

### 5.10 Provider Presets

Auto-detect IMAP/SMTP settings from MX records, with manual override:

| Provider | IMAP Host | IMAP Port | SMTP Host | SMTP Port |
|---|---|---|---|---|
| Gmail | imap.gmail.com | 993 | smtp.gmail.com | 465 |
| Outlook/365 | outlook.office365.com | 993 | smtp.office365.com | 587 |
| iCloud | imap.mail.me.com | 993 | smtp.mail.me.com | 587 |
| Yahoo | imap.mail.yahoo.com | 993 | smtp.mail.yahoo.com | 465 |
| Custom | (auto-detect from MX) | 993 | (auto-detect) | 587 |

Setup wizard shows provider selector → auto-fills hosts → user enters email + password → test connection → done.

---

## 6. Cron Setup

```bash
* * * * * cd /path/to/emmmail && php index.php sync >> /dev/null 2>&1
```

The `sync` action (CLI-only):
- Iterates all active accounts
- Connects to each IMAP server (60s budget per account)
- Fetches new messages since last sync
- Re-syncs flags (read/unread/starred) from server
- Detects server-side expunges
- Updates threads, contacts, FTS index
- Processes outbox (sends pending messages past their send_at time)
- Updates `sync_error` on failure for UI display
- Runs in <30 seconds for typical usage

---

## 7. Security Considerations

| Concern | Mitigation |
|---|---|
| **Password storage** | AES-256-GCM encrypted, key in `.env` file (gitignored, chmod 600) or env var |
| **XSS** | `htmlspecialchars()` on all output; HTML emails in sandboxed iframe (no `allow-scripts`) |
| **CSRF** | Per-session token, `X-CSRF-Token` header check, explicit exemption list |
| **Session security** | httponly, SameSite=Strict, secure (HTTPS), rename default PHPSESSID, regenerate on login/logout, idle timeout (30min) + absolute expiry (24h) |
| **Rate limiting** | Per-IP (15 attempts/15min) + per-account (5 failures/15min) on login; rate limit add_account, test_connection |
| **SQL injection** | PDO prepared statements throughout; `refs` column avoids SQL keyword |
| **File blocking** | `.htaccess` + PHP guard for `.sqlite`, `.env`, `.git`, `.bak`, etc. |
| **Sensitive headers** | Remove `X-Powered-By`, set `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy` |
| **Attachment safety** | Block dangerous extensions (php, html, js, etc.), `basename()` + containment check, serve via authenticated endpoint only, CSP sandbox, `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, PHP engine off in `attachments/` |
| **TLS verification** | `verify_peer => true`, `verify_peer_name => true` on all IMAP/SMTP connections |
| **SMTP injection** | Strip `\r` and `\n` from all user-supplied header values |
| **Remote content** | Block remote images by default in HTML emails, load on user click |
| **Sync security** | CLI-only (`PHP_SAPI === 'cli'`), optional cron token |
| **Outbox race** | Atomic claim: `UPDATE ... SET status='sending' WHERE status='pending'` before send |
| **Display errors** | `display_errors = off` in production |
| **Spelling** | Correct "Spelling" → "Spelling" (was a typo in original) |

### 7.1 CSP Header

```
Content-Security-Policy: default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline' 'unsafe-eval'; img-src 'self' data:; object-src 'none'; base-uri 'none'; frame-src 'none'; form-action 'self';
```

Note: `frame-src 'none'` blocks iframes in the app itself; HTML email iframes use `srcdoc` which is covered by `sandbox` attribute.

---

## 8. File Structure

```
emmmail/
├── index.php              # The entire application (~6500-7500 LOC)
├── .env                   # Encryption key + optional config (gitignored, chmod 600)
├── .env.example           # Template with EMMMAIL_KEY= (generated on first run)
├── test.php               # Self-contained test suite
├── .htaccess              # Apache rewrites + security
├── Justfile               # Task runner (dev server, tests, cron)
├── README.md              # User manual
├── LICENSE                # O'SAASy license
├── tests.php              # Test suite (182 assertions, 30 groups)
├── docs/
│   ├── plans/             # Feature plans
│   └── memory.md          # Session checkpoints
├── attachments/           # Email attachments (created at runtime, gitignored)
│   └── .htaccess          # Deny all direct access
└── emmmail.sqlite         # Database (gitignored)
```

---

## 9. Implementation Phases

### Phase 1: Foundation (Core + Auth + IMAP/SMTP)
- [ ] Database schema + migrations (with indexes, triggers, login_attempts)
- [ ] Encryption key management (env var / config file)
- [ ] Pure PHP IMAP client (connect, login, list folders, fetch messages, TLS verification, stream timeouts)
- [ ] Pure PHP SMTP client (connect, login, send, TLS verification, header injection prevention)
- [ ] MIME parser (headers, body, attachments, date normalization, charset conversion)
- [ ] Auth system (IMAP-only login, sessions with regeneration, CSRF, rate limiting per-IP + per-account)
- [ ] First-run setup wizard (with provider presets, MX auto-detect, test connection)
- [ ] Basic frontend (login, setup, inbox view with proper breakpoints)

### Phase 2: Mail Features
- [ ] Message list (threaded view, avatars, preview, date, unread badges)
- [ ] Message view (HTML in sandboxed iframe, attachments, quoted-text collapse)
- [ ] Compose / Reply / Forward (contenteditable, recipient chips, attachments, autosave)
- [ ] Send + Debounced outbox (JS-driven delivery, countdown toast, undo)
- [ ] Drafts (save/load/delete, autosave, IMAP Drafts folder sync)
- [ ] Flags (read/unread, star/unstar, flag re-sync from IMAP)
- [ ] Move/Delete/Expunge (IMAP operations)
- [ ] Sync engine (cron command, per-account lock, flag re-sync, expunge reconciliation)
- [ ] Offline/sync status indicator

### Phase 3: Organization
- [ ] Tags (create, rename, apply, filter, color picker)
- [ ] Search (FTS5 with advanced filters: from:/to:/subject:/date/has:attachment)
- [ ] Contacts (auto-extract, autocomplete in compose, contact list view)
- [ ] Multiple accounts (add from settings, per-account sync status)
- [ ] Account switching (topbar dropdown, combined inbox option)
- [ ] Multi-select + batch actions (archive, delete, star, mark read, tag)

### Phase 4: Polish
- [ ] Keyboard shortcuts (complete Gmail set with `inInput` guard)
- [ ] Dark/light theme (CSS custom properties, prefers-color-scheme)
- [ ] Mobile responsive (bottom tab bar, drawer sidebar, touch gestures)
- [ ] Undo system (generalized undo for archive/delete/tag with `z` shortcut)
- [ ] Empty states + loading skeletons
- [ ] Remote image blocking (privacy, load on click)
- [ ] Self-update mechanism
- [x] Test suite (`tests.php` — 182 assertions, 30 groups, all passing)
- [ ] Documentation

---

## 10. Estimated Size

| Component | LOC |
|---|---|
| PHP backend (routing, API, auth) | ~2200 |
| IMAP client | ~400 |
| SMTP client | ~200 |
| MIME parser | ~450 |
| Sync engine | ~350 |
| Database schema + migrations | ~250 |
| HTML template | ~350 |
| CSS (themes, responsive, mobile) | ~900 |
| JavaScript (frontend logic, compose, shortcuts) | ~2500 |
| **Total** | **~7600** |

Comparable to Tasssks (6620 LOC) — fits the one-file philosophy with room for the additional complexity of IMAP/SMTP/MIME.

---

## 11. Open Questions (Resolved)

| Question | Decision |
|---|---|
| IMAP vs POP3 | IMAP + SMTP (sync-based) |
| Sync mechanism | Cron + PHP (background) |
| App-level auth | IMAP-only login (credentials = email password) |
| Attachment storage | Local filesystem |
| HTTPS | Operator handles (reverse proxy) |
| Undo delay | 10 seconds (fixed) |
| Account limit | Unlimited |
| Attachment size | 25MB per message (standard SMTP limit) |
| IMAP folder sync | All folders (cached for sync state) |
