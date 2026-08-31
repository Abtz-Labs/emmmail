# Setup wizard: preset selection + custom SMTP/IMAP settings

status: done
date: 2026-08-31

## Problem

On first run the onboarding screen only shows Email + Password. The user
cannot choose a provider preset (Gmail, Yahoo, Outlook, Migadu, iCloud) and
cannot define custom IMAP/SMTP server, port, or TLS settings. Test connection
sends only email/password, so `test_connection` always fails with
"Missing required field: imap_host", which keeps "Finish setup" disabled.
Also, `auth` returns no `session_token`, so first-run setup leaves the user
logged out.

## Goal

The onboarding wizard must let the user:
1. Pick a provider preset that fills IMAP/SMTP host, port, and TLS fields.
2. Manually edit host, port, and TLS (custom server config).
3. Test the connection with the chosen server config.
4. Finish setup and land logged in on the inbox.

## Approach

Frontend-only changes in `serveFrontend()` (index.php). The backend already
supports full IMAP/SMTP config: `auth`/`test_connection` accept either
`preset` or `imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls`.
`auth` returns `account_id` but no session; we obtain a token by calling
`login` with the same credentials right after a successful `auth`, and store
it (same pattern as `doLogin`).

### Markup (renderSetup)

- `select.input[data-spreset]` with options: gmail, yahoo, outlook, migadu,
  icloud, custom (placeholder "Select a provider or configure manually").
- IMAP block: `input[data-simap-host]`, `input[data-simap-port]`,
  `select[data-simap-tls]` (1=SSL/TLS, 2=STARTTLS, 0=None).
- SMTP block: `input[data-ssmtp-host]`, `input[data-ssmtp-port]`,
  `select[data-ssmtp-tls]`.
- Keep `data-semail`, `data-spassword`, `data-test`, submit buttons.
- On `data-spreset` change: fill IMAP/SMTP fields from a small JS `PROVIDERS`
  map (mirrors server `getPresets()` values); `custom` leaves fields as-is.

### testConnection

- Build payload with imap/smtp host, port, tls + email/password.
- On success, toast + enable submit (existing pattern).

### submitSetup

- Build same payload; call `auth`, then `login` to obtain `session_token`,
  store in `localStorage['emmmail-session']`, `await this.init()`, navigate
  `#inbox`.

### CSS

- Add `.field-row` grid (host/port/TLS triads) + `.setup-server-title`.

## Tests (RED)

In `testFrontend()` group (tests.php), assert `serveFrontend()` contains:
- `data-spreset` (preset selector)
- `data-simap-host`, `data-simap-port`, `data-simap-tls`
- `data-ssmtp-host`, `data-ssmtp-port`, `data-ssmtp-tls`
- `PROVIDERS` map and SSL/TLS/STARTTLS option labels
- `submitSetup` uses `login` (obtain session token) — string marker

## Verification

1. `php tests.php` — expect all PASS, 3 skip.
2. `node --check` on extracted app script still passes (existing guard).
3. Reload http://localhost:2525/, confirm wizard shows preset + server fields,
   test connection works, finish lands on inbox.