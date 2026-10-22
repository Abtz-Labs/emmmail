# Environment

## The imap extension
Installed with `brew install shivammathur/extensions/imap@8.5`. **PECL fails on PHP 8.5** with
`U8T_CANONICAL is missing`.

Config: `/opt/homebrew/etc/php/8.5/conf.d/20-imap.ini`.

## Certificates
System CA bundle: `/opt/homebrew/etc/openssl@3/cert.pem`.

`cafile => null` stays in a stream context and makes `stream_socket_enable_crypto()` throw
`ValueError: Path must not be empty`. `tls_ssl_options(bool $verify)` omits the key unless a
non-blank path is set.

## Migadu
`imap.migadu.com:993` (IMAP) and `smtp.migadu.com:465` (SMTP). **Never derive the server from
the email domain** — the login page uses a preset dropdown only, default `migadu`, with no
auto-detect.

## Production deployment (webmail.abtz.co)
- `vps-4` under `abtz2`, Hestia layout: releases in
  `/home/abtz2/web/webmail.abtz.co`, document root `public_html`, data in `private/`.
- `bare.config.json` is **git-ignored**: it carries the production
  `EMMMAIL_ENCRYPTION_KEY` used by the deploy post-script.
- `SetEnv` does **not** reach PHP-FPM through `mod_proxy_fcgi`. The post-script
  appends `ProxyFCGISetEnvIf "true" NAME "value"` to each release's `.htaccess`
  instead. Hestia runs this domain on PHP 8.1 with `ext-imap`.
- Production lock: `EMMMAIL_ALLOWED_DOMAINS=abtz.co` and
  `EMMMAIL_FORCE_PRESET=migadu`. Both are read per request.

## TCP timeouts
`imap_open()` blocks forever on an unreachable host and segfaults PHP at shutdown, and c-client
ignores `default_socket_timeout` for TCP connect. See [imap.md](imap.md) for the bounded probe
in `MailImap::__construct`.
