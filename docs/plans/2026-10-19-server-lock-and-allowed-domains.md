---
status: in progress
---

# Lock the server and the sign-in domains from the environment

## Goal
An operator can pin a deployment to one mail provider, or one IMAP/SMTP host pair, and
can limit sign-in to a list of email domains.

- `EMMMAIL_PROVIDER=<preset key>`, which is one of `server_presets()`.
- `EMMMAIL_IMAP_HOST` + `EMMMAIL_SMTP_HOST`. These reuse the existing
  `EMMMAIL_IMAP_PORT` / `_SMTP_PORT` / `_IMAP_ENCRYPTION` / `_SMTP_ENCRYPTION`.
- `EMMMAIL_MAIL_SERVER` now locks as well, and it fills whichever host is still blank. The
  README always said it "forces" a host. Before this change it only pre-filled the custom
  fields.
- `EMMMAIL_ALLOWED_DOMAINS=example.com,example.org` takes commas or whitespace,
  case-insensitive. A leading `@` is tolerated. Matching is exact, so subdomains do not count.

Decisions (asked):
- A locked login page shows only email + password, with no dropdown and no custom fields.
- Bad lock config fails closed: an unknown provider, a provider set beside a host var, or
  only one host given. The login page shows an inline alert and every sign-in is refused.
- The domain list is checked at sign-in only. Existing sessions are left alone.

## Shape
- `server_lock()` returns `['locked', 'error', 'config']`. It reads the env at call time
  (like `tls_ssl_options()`), so tests can `putenv()`.
- `allowed_domains()` and `email_domain_allowed()` are pure. An empty list allows everything.
- `login_gate($email)` returns `''` or a user-facing message. `handle_login()` calls it
  before the rate limiter and before any `MailImap`, so a refused address never reaches
  the server and is not recorded as a failed attempt.
- When locked, `handle_login()` takes `server_lock()['config']` and ignores the posted
  `preset` and custom fields.
- The login JS keeps the preset/custom memory untouched when the page has no
  `#login-preset`. Otherwise a locked page would wipe a user's stored servers.

## Steps
1. [x] Red tests: `server_lock()`, `allowed_domains()`, `email_domain_allowed()`,
   `login_gate()`, the `view_login()` markup in the locked and misconfigured states, and a
   source-order guard on `handle_login()`. Plus JS S69e: no preset select, so submit leaves
   the memory alone.
2. [x] Implement. Also added `.login-alert`, which uses the existing `--danger` and
   `--danger-soft` tokens, so no new token was needed.
3. [x] README: configuration table + a "Locking a deployment" section.
4. [x] Green `just test`. The footer test expected `(c)` while the code renders `&copy;`.
   That test was already failing before this plan, and it has been fixed to match the code.
5. [ ] Browser pass NOT run, because the terminal browser could not attach. Checked over
   HTTP instead:
   - A `migadu` lock renders no `#login-preset` and no `.custom-servers`.
   - `bogus` renders the `.login-alert`.
   - `me@evil.io` gets the domain toast in about 4ms, which means no IMAP connection was made.

   Still owed: what the alert looks like in both themes, and a real sign-in on a locked
   provider.
