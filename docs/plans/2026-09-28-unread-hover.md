# Unread row hover is invisible

**Status:** done
**Date:** 2026-09-28

## Problem

Hovering a **read** message shifts the row to `--surface-2`, a clear visual
change. Hovering an **unread** message leaves it at `--accent-soft` — the exact
colour it rests at — so the pointer being over a new message is invisible.

## Goal

Give unread rows a distinct hover state: still clearly "accent-tinted" (so the
read/unread difference survives), but noticeably stronger than the resting tint.

## Plan

### Step 1: Add `--accent-soft-hover` token

In all three theme blocks (light `:root`, `[data-theme="dark"]`, and the
`prefers-color-scheme` media clone), add a stronger accent tint:

- light: `--accent-soft-hover:rgba(94,129,172,.22);` (resting is `.12`)
- dark: `--accent-soft-hover:rgba(136,192,208,.28);` (resting is `.16`)

### Step 2: Point `.mail.unread:hover` at it

Change `.mail.unread:hover { background:var(--accent-soft); }` to
`.mail.unread:hover { background:var(--accent-soft-hover); }`.

Read rows keep `--surface-2` on hover — unchanged.

### Step 3: Tests (unit.php, css group)

- assert `--accent-soft-hover` exists in the CSS
- assert `.mail.unread:hover` uses `--accent-soft-hover`
- assert the two values differ from `--accent-soft` (i.e. hover actually changes)

### Verification

- `php tests/run.php` green (**1881 passed, 0 failed**, 2 live suites skipped).
- `php -l` clean.
- Manual: hover an unread row → it visibly darkens; the unread tint is still
  distinguishable from read rows.

## Outcome

Implemented with TDD (red tests first):

| Step | Change | Status |
|---|---|---|
| 1 | `--accent-soft-hover` token added to all three theme blocks (light `.12`→`.24`, dark `.16`→`.30`) | done |
| 2 | `.mail.unread:hover` now uses `var(--accent-soft-hover)` instead of the resting `--accent-soft` | done |
| 3 | Unit tests: token exists, the hover rule references it, and it differs from the resting tint | done |

Read rows still hover to `--surface-2`; only the unread hover state was fixed.