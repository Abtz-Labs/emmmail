# 2026-11-05 — reply bounced as a forwarding loop

## Status
- **Fix + tests done; a live resend is owed.** Plan:
  `docs/plans/2026-11-05-delivered-to-forwarding-loop.md`.

## Cause
- `smtp_build_raw_message()` added `Return-Path:` and `Delivered-To: <to>` to every message.
- Postfix reads `Delivered-To:` for loop detection (`delivered_hdr_find()`), so netcup rejected
  the reply with `5.4.6 mail forwarding loop for mail@sysscore.com`.
- Gmail Mobile worked because it never adds that header.

## Fix
- Removed both trace headers from `smtp_build_raw_message()`. Envelope + `From:` carry identity.

## Tests
- `tests/unit.php`: no `Delivered-To:`, no `Return-Path:` in the built message.
- `php tests/run.php`: **3233 passed, 0 failed, 2 smoke skips**.
- Owed: resend to `mail@sysscore.com` and confirm delivery.
