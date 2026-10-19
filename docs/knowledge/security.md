# Security

## The CSP is not a script barrier
It reads `script-src 'self' 'unsafe-inline'`, because `render_page` emits an inline
`<script>` (the theme bootstrap, the onboarding trigger). A nonce would be a real barrier.
**Treat inline script in the main DOM as trusted-adjacent, not as safe**, and never treat the
CSP as the reason something is OK.

## The read iframe
The message body renders in an iframe with `sandbox="allow-same-origin"` and **NO
`allow-scripts`**.

- `sandbox=""` gives an opaque origin, so `contentWindow.document` *throws* in the parent.
  That is why `allow-same-origin` is required for the height measurement.
- That combination is only safe while `allow-scripts` stays off. There is a **source-level
  test** for that, because no runtime test would notice its absence.
- Height = `max(body, documentElement) + 30`, measured in try/catch, refitted by a
  `ResizeObserver`.
- `overflow:hidden` is set **from JS after a successful measure, never in CSS**. In CSS it
  truncates the body when the measurement fails.

## sanitize_css()
CSS is allowed in mail *because of* the iframe above. `sanitize_css()` still cuts:

- `<`, and comments **before** token matching, so `expr/*x*/ession()` cannot reassemble;
- `@import`, `@charset`, `@namespace`;
- declarations with `expression`, `behavior` or `binding`;
- `javascript:`, `vbscript:`, `data:`.

`>` is deliberately **kept**: it is the child combinator. Consequence: inline base64 images
do not render, consistent with the `img src` channel.

## sanitize_html()
It must **recurse into a disallowed element's children before unwrapping**. Without that, a
`<font>` wrapper smuggled `<script>`, `javascript:` and `target` through. `$dropEntirely`
drops a subtree whole.

## Remote images
Off by default. `images_allowed()` whitelists one **exact** address per line. Two channels,
one gate: the `src`/`background=` attribute and the CSS `url()`. Blocked URLs are parked on
`data-blocked-src` with `width`/`height` intact, so the layout does not jump. The
`auto_show_images` setting ORs in on top of the `show_remote_images` toggle.

**Assertion trap:** `data-blocked-src="https://x"` *contains* `src="https://x"`, so a test
asserting on `src="https://x"` passes against a blocked image. Assert on `' src="'`, with the
leading space.

## Gates are server-side
Protected folders are enforced by pure gates (`folder_create_check`, `folder_delete_check` ->
`ok|protected|invalid`) **before any connection is opened**. Hiding a button is presentation,
not a control. `folder_move_check()` also builds `move_target_options()`, so the picker can
never offer a move the server will refuse. `folder_empty_check()` is the only thing between a
stray folder name and total loss, and `emptyFolder()` deletes by SEQUENCE NUMBER.

## Addresses compare case-insensitively
RFC 5321 says the local part is case-sensitive. Honouring that puts the user back in their own
reply-all, because their own address is on every message they receive. Compare
case-insensitively and count *other* recipients, not raw ones.

## Bcc is never a header in transit
`smtp_raw_with_bcc()` folds Bcc onto the already-built bytes for the Sent copy only.
Rebuilding the message would change the Message-ID and the MIME boundaries. The same rule
applies to any "second copy" of a message.

## Session and login
The password lives only in the session, encrypted with libsodium secretbox. The key is
server-global (`EMMMAIL_ENCRYPTION_KEY`), not session-scoped. One CSRF token per request,
shared by all forms (`csrf_page_token()`). The login page is a preset dropdown only — never
auto-detect, and never derive the server from the email domain. `login_attempts` throttles.

The operator can lock the server from the environment: `EMMMAIL_PROVIDER`, or the
`EMMMAIL_IMAP_HOST`/`EMMMAIL_SMTP_HOST` pair, or `EMMMAIL_MAIL_SERVER`. A locked deployment
renders no dropdown, and `handle_login()` ignores the posted preset and custom fields. A
lock that cannot be resolved **fails closed**: `server_lock()` stays locked with no config,
and every sign-in is refused. `EMMMAIL_ALLOWED_DOMAINS` is matched exactly on the part after
the last `@`. `login_gate()` runs both refusals **before the rate limiter and before any
`MailImap`**, and a source-order test guards that.

`detect_post_overflow()` and `parse_php_size()` run **before** `csrf_verify()` in
`handle_save_draft` and `handle_send`: past `post_max_size` PHP empties `$_POST`/`$_FILES`, so
without the early check the user sees "Security token invalid." instead of "Message too
large".
