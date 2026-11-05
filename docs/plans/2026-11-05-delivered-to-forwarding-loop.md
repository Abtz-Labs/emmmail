---
status: in progress (fix + tests done; a live resend is owed)
---

# A reply bounced with "mail forwarding loop"

## Problem

A reply sent from Emmmail to `mail@sysscore.com` bounced from `mxe9ec.netcup.net`:

```
mail@sysscore.com: mail forwarding loop for mail@sysscore.com
Status: 5.4.6
```

The same message sent from Gmail Mobile was delivered.

## Cause

`smtp_build_raw_message()` put two trace headers on every outgoing message:

```
Return-Path: <hello@abtz.co>
Delivered-To: mail@sysscore.com
```

`Delivered-To:` is the header Postfix's delivery agents use to break forwarding loops
(`delivered_hdr_find()` in `delivered_hdr.c`). A message that already names the recipient there
claims it has already been delivered to that recipient, so Postfix rejects it as a loop —
exactly `5.4.6 mail forwarding loop for <rcpt>`. Gmail never saw the header because Gmail never
sent it.

`Return-Path:` is the same class of error: RFC 5321 4.4 says the final delivery MTA inserts it.
Both lines came in with the initial import; nothing read them.

## Fix

`index.php`, `smtp_build_raw_message()`: drop both header lines. The SMTP envelope
(`MAIL FROM:<hello@abtz.co>`) and `From:` carry the identity. The same `$raw` feeds the wire and
the Sent copy, so both lose the headers; nothing else changes.

## Tests

- `tests/unit.php`, group `smtp_build_raw_message()`: the built message contains no
  `Delivered-To:` and no `Return-Path:`.
- `php tests/run.php`: **3233 passed, 0 failed, 2 smoke skips** (3231 before the two new tests).

## Verification

- Byte check of a built message: the header block now starts at `From:`; no trace headers.
- Owed: resend the reply to `mail@sysscore.com` and confirm delivery.
