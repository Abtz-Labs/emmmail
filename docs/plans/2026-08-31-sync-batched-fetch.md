# Sync: batched UID FETCH

status: in progress
date: 2026-08-31

## Problem

First full sync does one IMAP round trip per message (~269 requests ≈ 93s for
the test account). Providers reject excessive/fast connections and the UI just
shows a spinner. There is no incremental progress UI; the fix that matters is
making sync fast.

## Goal

Fetch message bodies in a few chunked `UID FETCH` commands instead of one per
message, cutting sync from ~93s to seconds. Single request stays; no UI change.

## Approach

- Add `ImapClient::parseBodies(array $lines): array` — parses the `lines[]`
  output of `command('UID FETCH <uid list> (BODY.PEEK[] FLAGS)')` (multiple
  `* <seq> FETCH (...BODY[] {n}` entries, each literal body, then a following
  ` FLAGS (...)` line). Keyed by UID, returns `['uid','raw','flags']` in
  response order. Unit-tested via ReflectionMethod (imapReflector pattern).
- Add `ImapClient::fetchBodies(array $uids): array` — chunks UIDs (50 each)
  into `UID FETCH <u1>,<u2>,... (BODY.PEEK[] FLAGS)` and merges parseBodies.
  Returns [] when no uids.
- `actionSync`: replace the per-message `fetchMessageBody($uid)` loop with one
  `fetchBodies($uidsToFetch)` call (guarded per folder: on throw, process empty
  set); keep per-message try/catch for store steps.

## Tests (RED)

- `testImapFetchBodiesParser()` via reflection with a captured-style lines log:
  multi-message bodies+flags, messages without flags, empty literal, arbitrary
  UIDs.
- Existing suite stays green.

## Verification

1. `php tests.php` — all PASS, 3 skip.
2. Real sync on dev account finishes in seconds (was ~93s).