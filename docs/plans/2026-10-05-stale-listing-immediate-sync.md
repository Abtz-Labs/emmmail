# Sync a stale listing the moment it opens

status: code complete, browser pass not run

## Problem

Right after login the inbox paints from message-header rows that survive logout
(the cache is kept by design). `folder_view_data()` only syncs server-side on a
*cold* cache (`index.php:2472`), so a present-but-old cache is served as-is. The
client poll chain's first tick is a whole `SYNC_CLIENT_MIN_PERIOD` (60s) away
(`index.php:8567`, `8827`), so the chip reads "6 days ago" and the list stays
stale until then.

## Decision

A **stale-gated boot poll**. When a listing's cached headers are older than the
poll period, the client syncs it once at startup, then the normal 60s chain
continues. Fresh pages keep today's cadence. (Chosen over an unconditional boot
poll, which would shift the call-count baseline of ~25 poll tests and swallow a
chip click during the boot request.)

## Design

- New pure helper `sync_is_stale(int $lastSyncTs, int $now, int $period): bool`:
  true when the stamp is missing/zero, or `now - lastSyncTs >= period`.
- `view_inbox()` adds `data-sync-stale="1"` to the `.sync` chip when
  `sync_is_stale($d['last_sync_ts'], time(), SYNC_CLIENT_MIN_PERIOD)`.
- JS: a small `syncStaleAtBoot()` reads that attribute; at boot, if the topbar
  period > 0 and the chip is stale, call `syncPoll(false)` (it reschedules
  itself); else `syncSchedule()` as today. Non-listing pages have no `.sync`, so
  they are unchanged.

## Steps

1. [x] Red PHP test: `sync_is_stale()` truth table; `view_mailbox()` emits the
   attribute for an old stamp and omits it for a fresh one.
2. [x] Red JS test: a stale chip syncs once at boot before any tick and keeps the
   chain; a non-stale one still waits the whole period (S62 a).
3. [x] Implement the helper, the attribute, and the boot branch.
4. [x] `just test` green (2856 passed, 2 smoke skips); knowledge + memory updated.

Manual browser pass still owed: log in with a cache older than a minute and watch
the inbox correct itself on load, and confirm a fresh page still waits its period.
