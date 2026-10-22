---
status: in progress
---

# Deploy to webmail.abtz.co + login lock

## Goal

1. Deploy `emmmail` with `bare` to `vps-4` under the `abtz2` account, served at
   `https://webmail.abtz.co`.
2. Lock the login to the `abtz.co` domain and the `migadu` preset through
   environment variables.
3. Keep the SQLite database outside the document root, in
   `/home/abtz2/web/webmail.abtz.co/private`.
4. Keep `bare.config.json` git-ignored (it carries the production encryption key).

## Login lock

Two environment variables, read at request time so the tests can drive them:

| Variable | Meaning |
|---|---|
| `EMMMAIL_ALLOWED_DOMAINS` | Comma- or space-separated email domains. Empty/unset = no restriction. Exact domain match, case-insensitive, a leading `@` is accepted. |
| `EMMMAIL_FORCE_PRESET` | A key from `server_presets()`. Empty/unknown = the user chooses. |

Behavior:

- `resolve_mail_config()` ignores the posted preset and custom server fields when
  a valid forced preset is set.
- `handle_login()` rejects an address outside the allowlist before connecting to
  IMAP. No login attempt is recorded.
- `view_login()` renders a hidden `preset` input and no preset selector or custom
  fields when a preset is forced.
- The login JS must not overwrite the remembered preset or custom fields when the
  selector is absent.

## Steps

| # | Step | Verify |
|---|---|---|
| 1 | Red tests: parse helper, domain gate, forced preset resolution, login view, JS guard | `php tests/unit.php` and `php tests/js.php` show the new tests failing |
| 2 | Implement the helpers, `resolve_mail_config()`, `handle_login()`, `view_login()` | the red tests pass |
| 3 | Implement the JS guard | the JS suite passes |
| 4 | Create `package.json` (bare requires one) and `bare.config.json`; ignore the config in `.gitignore` | `node -e` parses both; `bare deploy --dry-run` passes |
| 5 | Document the variables and the deployment layout | README + knowledge/environment.md match the code |
| 6 | Run every suite | `just test` green |

## Server wiring

- `deployTo`: `/home/abtz2/web/webmail.abtz.co`
- `webroot`: `/home/abtz2/web/webmail.abtz.co/public_html`
- Database: `/home/abtz2/web/webmail.abtz.co/private/emmmail.sqlite`
- PHP 8.1 with `ext-imap` (confirmed on the server).
- On every release, a post-script appends `ProxyFCGISetEnvIf` lines to the
  release's `.htaccess` so PHP-FPM receives `EMMMAIL_DB_PATH`,
  `EMMMAIL_ALLOWED_DOMAINS`, `EMMMAIL_FORCE_PRESET`, and
  `EMMMAIL_ENCRYPTION_KEY`. `SetEnv` does not reach PHP-FPM through
  `mod_proxy_fcgi`; `ProxyFCGISetEnvIf` does.
- The encryption key is generated once and kept only in the git-ignored
  `bare.config.json`.
- Health check: `https://webmail.abtz.co/` (already answers 200).

## Out of scope

- Migrating the local development database or attachments.
- Changing the IMAP/SMTP providers.

## Notes

- `package.json` exists only because `bare` requires one for release IDs. Its version is bumped
  by `bare`; `APP_VERSION` in `index.php` is separate and unchanged by a deploy.
- The encryption key is generated once. Rotating it invalidates every stored session password.
