# Emmmail

A single-file, self-hosted email client built with PHP and SQLite.

No frameworks. No Composer. No build tools. One file, one database, done.

## Concept

Emmmail was born from a desire for simplicity. Most web email clients are bloated, require complex infrastructure, or lock your data behind a subscription. Emmmail takes the opposite approach: drop one PHP file on any server with PHP 8.1+ and you have a fully functional email client with IMAP/SMTP support, thread grouping, search, and a Gmail-inspired interface.

The entire application (backend logic, frontend markup, CSS, and JavaScript) lives in a single `index.php` file. The database is a SQLite file created automatically on first run. Attachments go into a single directory. That's the whole stack.

## Features

- Pure PHP IMAP and SMTP clients (no ext-imap dependency)
- IMAP/SMTP login (credentials validated against the mail server)
- AES-256-GCM password encryption (key stored in `.env`)
- Thread grouping by subject
- Compose and send emails via SMTP
- Draft management (save, list, update, delete)
- Attachment storage and download
- Provider presets (Gmail, Yahoo, Outlook, Migadu, iCloud)
- Session-based authentication with IP binding
- Rate limiting for login attempts
- SSRF protection (blocks private/reserved IPs)
- CRLF injection prevention
- Message listing with cursor-based pagination
- Full message retrieval with body and attachments
- Thread view with all messages
- Flag management (read/starred) and message moves
- Bulk operations (batch read, star, delete, archive, move)
- Full-text search (FTS5)
- Multi-account support (add, switch, delete)
- Server auto-detection (presets + MX lookup)
- Connection testing without saving
- Contact management (auto-populated from messages, search, autocomplete)
- Tag management (create, rename, delete, case-insensitive uniqueness)
- Message tagging (tag, untag, batch tag/untag)
- Per-account settings (theme, signature, preferences)
- File upload for compose attachments
- IMAP EXPUNGE for permanent deletion
- Health check and setup detection endpoints
- Self-update with syntax validation and backup
- Complete SPA frontend (login, setup, message list, thread view, compose, settings, contacts, search)
- Dark/light theme with CSS variables
- Keyboard shortcuts (c=compose, r=reply, e=archive, #=delete, s=star, j/k=navigate, /=search)
- Mobile-first responsive design with bottom tabs
- Full test suite (TDD, 500 assertions)
- [TODO] Keyboard shortcuts
- [TODO] Dark/light theme
- [TODO] Mobile-first responsive design

## Requirements

- PHP 8.1 or later
- SQLite3 extension (usually bundled with PHP)
- OpenSSL extension (for AES-256-GCM encryption)
- A web server (Apache, Nginx, or PHP's built-in server for development)

## Installation

1. Copy `index.php` to your web root (or any directory served by PHP).
2. Make sure the directory is writable (for the SQLite database and `.env` file).
3. Open it in a browser. The setup screen will walk you through adding your first email account.

That's it.

For development (requires [just](https://github.com/casey/just)):

```
just start
```

With a custom port:

```
just start 3000
```

Run in background:

```
just start background
```

Both options combined:

```
just start 3000 background
```

Stop the server:

```
just stop
```

Or directly:

```
php -S localhost:2525 index.php
```

## API

All endpoints are called via `index.php?action=<action>`. Requests use JSON POST bodies with `Content-Type: application/json`.

### Health Check

#### `ping`

Health check endpoint. Returns version and server time. Public (no session required).

```bash
curl -s http://localhost:2525/?action=ping | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "pong": true,
    "version": "1.0.0",
    "time": "2026-08-26T12:00:00+00:00"
  }
}
```

#### `setup`

Check if first-time setup is needed (no accounts configured). Public (no session required).

```bash
curl -s http://localhost:2525/?action=setup | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "setup": true
  }
}
```

### Authentication

#### `auth`

Test IMAP and SMTP connections with the given credentials. On success, persists (or updates) the account in the database.

**With a preset** (recommended):

```bash
curl -s http://localhost:2525/?action=auth \
  -H 'Content-Type: application/json' \
  -d '{
    "preset": "migadu",
    "email": "you@example.com",
    "password": "your-password"
  }' | jq
```

**With custom server config** (no preset):

```bash
curl -s http://localhost:2525/?action=auth \
  -H 'Content-Type: application/json' \
  -d '{
    "imap_host": "mail.example.com",
    "imap_port": 993,
    "imap_tls": 1,
    "smtp_host": "mail.example.com",
    "smtp_port": 587,
    "smtp_tls": 2,
    "email": "you@example.com",
    "password": "your-password"
  }' | jq
```

**Available presets:** `gmail`, `yahoo`, `outlook`, `migadu`, `icloud`

| Preset | IMAP | SMTP |
|--------|------|------|
| `gmail` | imap.gmail.com:993 (SSL) | smtp.gmail.com:465 (SSL) |
| `yahoo` | imap.mail.yahoo.com:993 (SSL) | smtp.mail.yahoo.com:465 (SSL) |
| `outlook` | outlook.office365.com:993 (SSL) | smtp.office365.com:587 (STARTTLS) |
| `migadu` | imap.migadu.com:993 (SSL) | smtp.migadu.com:465 (SSL) |
| `icloud` | imap.mail.me.com:993 (SSL) | smtp.mail.me.com:587 (STARTTLS) |

Response (success):

```json
{
  "ok": true,
  "data": {
    "account_id": 1,
    "email": "you@example.com",
    "imap": {
      "ok": true,
      "error": "",
      "folders": [
        {"name": "INBOX", "flags": ["\\HasNoChildren", "\\Inbox"]}
      ]
    },
    "smtp": {
      "ok": true,
      "error": ""
    }
  }
}
```

Response (failure):

```json
{
  "ok": false,
  "error": "IMAP: connection failed; SMTP: connection failed"
}
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `preset` | string | no | Provider preset name (see table above). When provided, IMAP/SMTP settings are auto-resolved. |
| `email` | string | yes | Full email address |
| `password` | string | yes | Account password |
| `imap_host` | string | if no preset | IMAP server hostname |
| `imap_port` | int | if no preset | IMAP port (usually 993 for SSL) |
| `imap_tls` | int | if no preset | TLS mode: `0` = none, `1` = SSL, `2` = STARTTLS |
| `smtp_host` | string | if no preset | SMTP server hostname |
| `smtp_port` | int | if no preset | SMTP port (usually 465 for SSL, 587 for STARTTLS) |
| `smtp_tls` | int | if no preset | TLS mode: `0` = none, `1` = SSL, `2` = STARTTLS |

#### `login`

Authenticate with email and password. Returns a session token.

```bash
curl -s http://localhost:2525/?action=login \
  -H 'Content-Type: application/json' \
  -d '{
    "email": "you@example.com",
    "password": "your-password"
  }' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "session_token": "abc123...",
    "account_id": 1,
    "email": "you@example.com",
    "display_name": "Your Name"
  }
}
```

#### `logout`

Destroy the current session.

```bash
curl -s http://localhost:2525/?action=logout \
  -H 'X-Session-Token: abc123...' | jq
```

#### `session`

Check if the current session is authenticated.

```bash
curl -s http://localhost:2525/?action=session \
  -H 'X-Session-Token: abc123...' | jq
```

**Response (authenticated):**

```json
{
  "ok": true,
  "data": {
    "authenticated": true,
    "account_id": 1,
    "email": "you@example.com",
    "display_name": "Your Name"
  }
}
```

**Response (not authenticated):**

```json
{
  "ok": true,
  "data": {
    "authenticated": false
  }
}
```

### Session Management

All endpoints except `auth`, `login`, and `session` require a valid session token. The token can be provided via:

1. `X-Session-Token` header (recommended)
2. `EMMMAILSESSID` cookie

Sessions are bound to the IP address that created them and expire after 30 minutes of inactivity or 24 hours absolute.

### Sync

#### `list_folders`

List all folders for the authenticated account, sorted by role priority.

```bash
curl -s http://localhost:2525/?action=list_folders \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "folders": [
      { "id": 1, "name": "INBOX", "role": "inbox", "unread_count": 5, "total_count": 120 },
      { "id": 2, "name": "Sent", "role": "sent", "unread_count": 0, "total_count": 40 }
    ]
  }
}
```

#### `list_messages`

List messages in a folder with cursor-based pagination.

```bash
curl -s http://localhost:2525/?action=list_messages \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"folder": "INBOX", "limit": 20}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `folder` | string | yes | Folder name (e.g., `INBOX`) |
| `limit` | int | no | Max messages per page (1-100, default 50) |
| `cursor` | string | no | Pagination cursor from previous response |

**Response:**

```json
{
  "ok": true,
  "data": {
    "messages": [
      { "id": 1, "uid": 42, "subject": "Hi", "from_address": "a@b.com",
        "from_name": "Alice", "to_addresses": ["x@y.com"], "date": "2026-08-26T10:00:00Z",
        "is_read": 0, "is_starred": 0, "has_attachments": 1, "thread_id": 5,
        "preview": "Hey, check this out..." }
    ],
    "has_more": true,
    "next_cursor": "2026-08-25T09:00:00Z|38"
  }
}
```

#### `get_message`

Get a single message with full body and attachments.

```bash
curl -s http://localhost:2525/?action=get_message \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_id": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | yes | Message database ID |

**Response:**

```json
{
  "ok": true,
  "data": {
    "message": {
      "id": 1, "subject": "Hello", "from_address": "a@b.com",
      "text_body": "Plain text...", "html_body": "<p>HTML...</p>",
      "to_addresses": ["x@y.com"], "cc_addresses": ["z@w.com"],
      "is_read": 1, "is_starred": 0, "has_attachments": 1
    },
    "attachments": [
      { "id": 1, "filename": "report.pdf", "content_type": "application/pdf", "size": 32000 }
    ]
  }
}
```

#### `get_thread`

Get thread metadata and all messages in the thread, ordered by date.

```bash
curl -s http://localhost:2525/?action=get_thread \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"thread_id": 5}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `thread_id` | int | yes | Thread database ID |

**Response:**

```json
{
  "ok": true,
  "data": {
    "thread": { "id": 5, "subject": "Hello", "message_count": 3, "preview": "...", "is_read": 0, "is_starred": 1, "last_message_at": "2026-08-26T10:00:00Z" },
    "messages": [
      { "id": 1, "date": "2026-08-25T08:00:00Z", "from_address": "a@b.com", "text_body": "...", "to_addresses": ["x@y.com"], "cc_addresses": [] }
    ]
  }
}
```

#### `set_flags`

Update message flags (read/starred) or move to a different folder.

```bash
curl -s http://localhost:2525/?action=set_flags \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_id": 1, "is_read": 1, "is_starred": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | yes | Message database ID |
| `is_read` | int | no | 0 or 1 |
| `is_starred` | int | no | 0 or 1 |
| `folder_name` | string | no | Move to this folder (requires IMAP) |

#### `batch_action`

Bulk operations on multiple messages.

```bash
curl -s http://localhost:2525/?action=batch_action \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_ids": [1, 2, 3], "action": "read"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_ids` | int[] | yes | Array of message IDs (max 200) |
| `action` | string | yes | `read`, `unread`, `star`, `unstar`, `delete`, `archive`, `move` |
| `folder_name` | string | if action=move | Destination folder name |

**Response:**

```json
{ "ok": true, "data": { "affected": 3 } }
```

#### `search`

Full-text search across messages using FTS5.

```bash
curl -s http://localhost:2525/?action=search \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"query": "quarterly report"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `query` | string | yes | Search terms |
| `folder` | string | no | Limit to folder |
| `limit` | int | no | Max results (1-100, default 50) |

**Response:** Same shape as `list_messages` (without `has_more`/`next_cursor`).

### Accounts

#### `list_accounts`

List all configured email accounts (for account switcher UI).

```bash
curl -s http://localhost:2525/?action=list_accounts \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "accounts": [
      { "id": 1, "email": "you@example.com", "display_name": "You",
        "imap_host": "imap.migadu.com", "smtp_host": "smtp.migadu.com",
        "is_active": 1, "last_sync_at": "2026-08-26T10:00:00Z", "created_at": "2026-08-25T08:00:00Z" }
    ]
  }
}
```

#### `add_account`

Add a new email account. Tests IMAP/SMTP connectivity and returns a session token for the new account.

```bash
curl -s http://localhost:2525/?action=add_account \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{
    "preset": "migadu",
    "email": "other@example.com",
    "password": "your-password"
  }' | jq
```

**Parameters:** Same as `auth` (preset or manual IMAP/SMTP config, email, password).

**Response:**

```json
{
  "ok": true,
  "data": {
    "account_id": 2,
    "session_token": "def456...",
    "email": "other@example.com",
    "imap": { "ok": true, "error": "", "folders": [...] },
    "smtp": { "ok": true, "error": "" }
  }
}
```

#### `delete_account`

Delete the current session's account and all associated data (messages, folders, attachments, sessions).

```bash
curl -s http://localhost:2525/?action=delete_account \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{ "ok": true, "data": { "deleted": true, "account_id": 1 } }
```

Note: This destroys the current session. The client must handle the logged-out state.

#### `detect_server`

Auto-detect IMAP/SMTP servers from an email address. Public endpoint (no session required).

```bash
curl -s http://localhost:2525/?action=detect_server \
  -H 'Content-Type: application/json' \
  -d '{"email": "user@gmail.com"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `email` | string | yes | Email address to detect servers for |

**Response (known provider):**

```json
{
  "ok": true,
  "data": {
    "detected": true,
    "preset": "gmail",
    "imap_host": "imap.gmail.com", "imap_port": 993, "imap_tls": 1,
    "smtp_host": "smtp.gmail.com", "smtp_port": 465, "smtp_tls": 1
  }
}
```

**Response (unknown domain):**

```json
{
  "ok": true,
  "data": {
    "detected": false,
    "preset": null,
    "imap_host": "imap.example.com", "imap_port": 993, "imap_tls": 1,
    "smtp_host": "smtp.example.com", "smtp_port": 587, "smtp_tls": 2
  }
}
```

#### `test_connection`

Test IMAP/SMTP connectivity without saving the account. Public endpoint (no session required).

```bash
curl -s http://localhost:2525/?action=test_connection \
  -H 'Content-Type: application/json' \
  -d '{
    "preset": "migadu",
    "email": "you@example.com",
    "password": "your-password"
  }' | jq
```

**Parameters:** Same as `auth`.

**Response:**

```json
{
  "ok": true,
  "data": {
    "email": "you@example.com",
    "imap": { "ok": true, "error": "", "folders": ["INBOX", "Sent", ...] },
    "smtp": { "ok": true, "error": "" }
  }
}
```

### Contacts & Tags

#### `list_contacts`

List contacts sorted by use count (most-used first). Contacts are auto-populated from message addresses during sync.

```bash
curl -s http://localhost:2525/?action=list_contacts \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"q": "alice", "limit": 50}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `q` | string | no | Search by email or name (substring match) |
| `limit` | int | no | Max results (1-500, default 300) |

**Response:**

```json
{
  "ok": true,
  "data": {
    "contacts": [
      { "email": "alice@example.com", "name": "Alice", "use_count": 15 }
    ]
  }
}
```

#### `search_contacts`

Search contacts for autocomplete (prefix match, sorted by use count).

```bash
curl -s http://localhost:2525/?action=search_contacts \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"q": "ali"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `q` | string | yes | Search prefix |
| `limit` | int | no | Max results (1-20, default 10) |

**Response:**

```json
{
  "ok": true,
  "data": {
    "contacts": [
      { "email": "alice@example.com", "name": "Alice" }
    ]
  }
}
```

#### `list_tags`

List all tags for the account, sorted by name.

```bash
curl -s http://localhost:2525/?action=list_tags \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "tags": [
      { "id": 1, "name": "Important", "color": "#6366f1" }
    ]
  }
}
```

#### `create_tag`

Create a new tag.

```bash
curl -s http://localhost:2525/?action=create_tag \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"name": "Important", "color": "#ff0000"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | yes | Tag name (max 100 chars, case-insensitive unique) |
| `color` | string | no | Hex color (default `#6366f1`) |

**Response:**

```json
{ "ok": true, "data": { "id": 1, "name": "Important", "color": "#ff0000" } }
```

#### `rename_tag`

Rename an existing tag.

```bash
curl -s http://localhost:2525/?action=rename_tag \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"id": 1, "name": "Very Important"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | yes | Tag ID |
| `name` | string | yes | New tag name |

**Response:**

```json
{ "ok": true, "data": { "id": 1, "name": "Very Important" } }
```

#### `delete_tag`

Delete a tag. Also removes all message associations.

```bash
curl -s http://localhost:2525/?action=delete_tag \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"id": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `id` | int | yes | Tag ID |

**Response:**

```json
{ "ok": true, "data": { "deleted": true } }
```

#### `tag_message`

Add a tag to a message. Idempotent (no error if already tagged).

```bash
curl -s http://localhost:2525/?action=tag_message \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_id": 1, "tag_id": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | yes | Message ID |
| `tag_id` | int | yes | Tag ID |

**Response:**

```json
{ "ok": true, "data": { "tagged": true } }
```

#### `untag_message`

Remove a tag from a message.

```bash
curl -s http://localhost:2525/?action=untag_message \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_id": 1, "tag_id": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_id` | int | yes | Message ID |
| `tag_id` | int | yes | Tag ID |

**Response:**

```json
{ "ok": true, "data": { "untagged": true } }
```

#### `batch_tag`

Add or remove tags from multiple messages at once.

```bash
curl -s http://localhost:2525/?action=batch_tag \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"message_ids": [1, 2, 3], "tag_ids": [1, 2]}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_ids` | int[] | yes | Array of message IDs |
| `tag_ids` | int[] | yes | Array of tag IDs |
| `remove` | bool | no | If `true`, remove tags instead of adding |

**Response:**

```json
{ "ok": true, "data": { "updated": true } }
```

### Settings

#### `get_settings`

Get all settings for the current account with defaults applied.

```bash
curl -s http://localhost:2525/?action=get_settings \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "settings": {
      "signature": "",
      "theme": "dark",
      "show_remote_images": false,
      "sync_on_load": true
    }
  }
}
```

#### `update_settings`

Update settings for the current account. Only whitelisted keys are accepted.

```bash
curl -s http://localhost:2525/?action=update_settings \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"theme": "light", "show_remote_images": true}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `theme` | string | no | `dark` or `light` |
| `show_remote_images` | bool | no | Show remote images in messages |
| `sync_on_load` | bool | no | Auto-sync on app load |

**Response:** Returns full settings after update.

#### `update_signature`

Update the email signature for the current account.

```bash
curl -s http://localhost:2525/?action=update_signature \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"signature": "-- \nSent from Emmmail"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `signature` | string | yes | Signature text (max 10000 chars, empty string to clear) |

**Response:**

```json
{ "ok": true, "data": { "signature": "-- \nSent from Emmmail" } }
```

### Updates

#### `check_update`

Check if a new version is available. Returns current and latest version numbers.

```bash
curl -s http://localhost:2525/?action=check_update \
  -H 'X-Session-Token: abc123...' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "current": "1.0.0",
    "latest": "1.0.0",
    "update_available": false,
    "update_url": ""
  }
}
```

#### `apply_update`

Download and apply an update from the configured `update_url` setting. The downloaded file is validated (PHP syntax check + Emmmail build marker) before replacing the current `index.php`. A backup is created at `index.php.bak`.

```bash
curl -s http://localhost:2525/?action=apply_update \
  -H 'X-Session-Token: abc123...' | jq
```

**Prerequisites:** The `update_url` setting must be configured via `update_settings`.

**Response (success):**

```json
{
  "ok": true,
  "data": {
    "updated": true,
    "version": "1.0.0",
    "backup": "index.php.bak"
  }
}
```

**Response (no update_url):**

```json
{
  "ok": false,
  "error": "No update source configured. Set the \"update_url\" setting first."
}
```

### Sync

#### `sync`

Trigger a manual sync for an account. Fetches folders and messages from IMAP.

```bash
curl -s http://localhost:2525/?action=sync \
  -H 'Content-Type: application/json' \
  -d '{"account_id": 1}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `account_id` | int | no | Account ID. If omitted, syncs the first account. |
| `folder` | string | no | Sync only this folder (e.g., `INBOX`). |
| `since` | string | no | Only fetch messages since this date (ISO 8601). |

**Response:**

```json
{
  "ok": true,
  "data": {
    "account_id": 1,
    "folders_synced": 5,
    "messages_synced": 142,
    "last_sync_at": "2026-08-26T12:00:00Z"
  }
}
```

#### `expunge_folder`

Permanently delete messages marked for deletion in a folder (IMAP EXPUNGE). Typically used on the Trash folder.

```bash
curl -s http://localhost:2525/?action=expunge_folder \
  -H 'Content-Type: application/json' \
  -H 'X-Session-Token: abc123...' \
  -d '{"folder_name": "Trash"}' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `folder_name` | string | yes | Folder name to expunge |

**Response:**

```json
{ "ok": true, "data": { "expunged": true } }
```

### Compose

#### `compose`

Send an email or save as draft.

**Send immediately:**

```bash
curl -s http://localhost:2525/?action=compose \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": 1,
    "to": ["recipient@example.com"],
    "subject": "Hello",
    "body": "This is a test email."
  }' | jq
```

**Save as draft:**

```bash
curl -s http://localhost:2525/?action=compose \
  -H 'Content-Type: application/json' \
  -d '{
    "account_id": 1,
    "to": ["recipient@example.com"],
    "subject": "Hello",
    "body": "This is a draft.",
    "draft": true
  }' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `account_id` | int | yes | Account ID to send from |
| `to` | array | yes | Recipient email addresses |
| `cc` | array | no | CC recipients |
| `bcc` | array | no | BCC recipients |
| `subject` | string | yes* | Email subject (*required if body is empty) |
| `body` | string | yes* | Plain text body (*required if subject is empty) |
| `body_html` | string | no | HTML body (optional, sent as multipart) |
| `attachments` | array | no | Array of `{filename, content_type, content}` (base64 encoded) |
| `in_reply_to` | string | no | Message ID this is replying to |
| `draft` | bool | no | If `true`, save as draft instead of sending |

**Response (sent):**

```json
{
  "ok": true,
  "data": {
    "sent": true,
    "to": ["recipient@example.com"],
    "cc": [],
    "bcc": [],
    "subject": "Hello"
  }
}
```

**Response (draft saved):**

```json
{
  "ok": true,
  "data": {
    "draft_id": 1,
    "saved": true
  }
}
```

### Drafts

#### `drafts`

List all drafts for an account.

```bash
curl -s http://localhost:2525/?action=drafts \
  -H 'Content-Type: application/json' \
  -d '{"account_id": 1}' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "drafts": [
      {
        "id": 1,
        "to_addresses": ["recipient@example.com"],
        "cc_addresses": [],
        "subject": "Hello",
        "body": "This is a draft.",
        "body_html": null,
        "in_reply_to": null,
        "created_at": "2026-08-26 12:00:00",
        "updated_at": "2026-08-26 12:00:00"
      }
    ]
  }
}
```

#### `draft`

Get a single draft by ID.

```bash
curl -s http://localhost:2525/?action=draft \
  -H 'Content-Type: application/json' \
  -d '{"draft_id": 1}' | jq
```

#### `update_draft`

Update draft fields.

```bash
curl -s http://localhost:2525/?action=update_draft \
  -H 'Content-Type: application/json' \
  -d '{"draft_id": 1, "subject": "Updated Subject", "body": "Updated body."}' | jq
```

**Updatable fields:** `to_addresses`, `cc_addresses`, `bcc_addresses`, `subject`, `body`, `body_html`, `attachments`, `in_reply_to`

#### `delete_draft`

Delete a draft.

```bash
curl -s http://localhost:2525/?action=delete_draft \
  -H 'Content-Type: application/json' \
  -d '{"draft_id": 1}' | jq
```

### Attachments

#### `message_attachments`

List all attachments for a message.

```bash
curl -s http://localhost:2525/?action=message_attachments \
  -H 'Content-Type: application/json' \
  -d '{"message_id": 1}' | jq
```

**Response:**

```json
{
  "ok": true,
  "data": {
    "attachments": [
      {
        "id": 1,
        "filename": "document.pdf",
        "content_type": "application/pdf",
        "size": 1024
      }
    ]
  }
}
```

#### `download_attachment`

Download an attachment file.

```bash
# Direct download
curl -O -J 'http://localhost:2525/?action=download_attachment&attachment_id=1'

# Or via POST
curl -s http://localhost:2525/?action=download_attachment \
  -H 'Content-Type: application/json' \
  -d '{"attachment_id": 1}' --output document.pdf
```

#### `upload_attachment`

Upload a file for use in compose. Returns file metadata for inclusion in the `compose` endpoint's `attachments` array.

```bash
curl -s http://localhost:2525/?action=upload_attachment \
  -H 'X-Session-Token: abc123...' \
  -F 'file=@document.pdf' | jq
```

**Parameters:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | yes | File to upload (multipart/form-data) |

**Response:**

```json
{
  "ok": true,
  "data": {
    "filename": "document.pdf",
    "content_type": "application/pdf",
    "size": 32000,
    "tmp_path": "/path/to/tmp/file"
  }
}
```

The `tmp_path` is temporary. Include the file metadata in the `compose` endpoint's `attachments` array to send it.

## User Manual

[TODO — Add user manual sections as features are built.]

## Deployment

For production, use Apache or Nginx with PHP-FPM. The key security considerations:

- **Database location.** By default the SQLite file (`emmmail.sqlite`) is created in the same directory as `index.php`. Set the `EMMMAIL_DB` environment variable to place it outside the web root (recommended). Make sure the path is within PHP's `open_basedir` (common on shared hosting — ask your provider if unsure). Examples:

  ```bash
  # PHP built-in server (dev)
  EMMMAIL_DB=/path/to/data/emmmail.sqlite php -S localhost:2525 index.php

  # Apache (.htaccess or vhost)
  SetEnv EMMMAIL_DB /var/data/emmmail.sqlite

  # Nginx + PHP-FPM (server or location block)
  fastcgi_param EMMMAIL_DB /var/data/emmmail.sqlite;

  # Docker / systemd
  Environment=EMMMAIL_DB=/var/data/emmmail.sqlite
  ```

  On shared hosting, a safe location is often a `private/` subdirectory inside your web root (e.g. `/home/user/web/example.com/private/emmmail.sqlite`). This directory is typically outside the web root and within `open_basedir`.

- **Encryption key.** The `.env` file stores the AES-256-GCM key used to encrypt stored passwords. Keep it outside the web root in production, or set the `EMMMAIL_KEY` environment variable.

- **Deny direct file access.** The application blocks requests to `.sqlite`, `.env`, and `.git` paths internally, but your web server should also enforce this as a second layer.

- **Do not deploy `tests.php` to production.** It is a development-only file.

### Apache

A `.htaccess` file is included in the repository. It routes requests through `index.php`, blocks access to database and sensitive files, and denies access to `tests.php`. No extra setup needed if your Apache has `mod_rewrite` and `AllowOverride All`.

### Nginx

```nginx
location ~ \.(sqlite|sqlite3|db|sql|env|bak)$ {
    deny all;
}

location ~ /\.git {
    deny all;
}

location / {
    try_files $uri /index.php$is_args$args;
}
```

## Running Tests

```
just test
```

Or directly:

```
php tests.php
```

The test suite uses a temporary database and cleans up after itself. Real-server auth tests are skipped unless credentials are provided.

To run real-server auth tests, pass a preset and email. You'll be prompted for the password (kept out of bash history):

```
just test migadu you@example.com
```

Available presets: `gmail`, `yahoo`, `outlook`, `migadu`, `icloud`.

Or set the environment variables directly:

```
EMMMAIL_TEST_PRESET=migadu EMMMAIL_TEST_EMAIL=you@example.com EMMMAIL_TEST_PASSWORD=your-password php tests.php
```

## Contributing

Contributions are welcome. Please keep these principles in mind:

1. **Single-file architecture.** All application code stays in `index.php`. This is a deliberate constraint, not a limitation to work around.

2. **No external PHP dependencies.** No Composer, no autoloaders. If you need a library, either implement the necessary logic directly or reconsider the approach.

3. **Write tests.** New features should include corresponding tests in `tests.php`. Use TDD when possible (write failing tests first, then implement).

4. **Keep it simple.** If 50 lines of straightforward code solve the problem, don't write 200 lines of abstracted code. Match the existing style.

5. **Surgical changes.** A pull request should do one thing. Don't bundle unrelated cleanups or formatting changes.

### How to Contribute

1. Fork the repository.
2. Create a feature branch.
3. Write tests for your change.
4. Implement the change (make tests pass).
5. Submit a pull request with a clear description of what and why.

### Reporting Issues

Open an issue with steps to reproduce. Include your PHP version, browser, and any relevant error messages.

## License

[O'SAASy](https://osaasy.dev/). See [LICENSE](LICENSE) for details.

In short: use it, modify it, self-host it, distribute it. Just don't offer it as a competing hosted service where the software itself is the primary value.

Designed and built by [Rogerio Taques](https://x.com/rogeriotaques), the guy behind [Abtz Labs](https://abtz.co).
