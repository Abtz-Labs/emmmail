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
- Full test suite (TDD, 207 assertions)
- [TODO] Full-text search (FTS5)
- [TODO] Multi-account support
- [TODO] Folder/label management
- [TODO] Contacts with autocomplete
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

#### [TODO] `list_messages`

List messages in a folder. Supports pagination and filtering.

#### [TODO] `get_thread`

Get all messages in a thread.

#### [TODO] `get_message`

Get a single message by ID.

#### [TODO] `set_flags`

Mark messages as read/unread, starred/unstarred.

#### [TODO] `batch_action`

Move, delete, or archive multiple messages.

#### [TODO] `search`

Full-text search across all messages.

### Accounts

#### [TODO] `list_accounts`

List all configured email accounts.

#### [TODO] `add_account`

Add a new email account.

#### [TODO] `delete_account`

Remove an email account and its data.

#### [TODO] `detect_server`

Auto-detect IMAP/SMTP servers from an email address (via MX lookup and provider presets).

#### [TODO] `test_connection`

Test IMAP/SMTP connectivity without saving.

### Contacts & Tags

#### [TODO] `list_contacts`

List contacts for an account.

#### [TODO] `search_contacts`

Search contacts by name or email.

#### [TODO] `list_tags`

List all tags.

#### [TODO] `create_tag`

Create a new tag.

### Settings

#### [TODO] `get_settings`

Get user settings.

#### [TODO] `update_settings`

Update user settings.

#### [TODO] `update_signature`

Update email signature.

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
