# 2026-11-05 — Esc from a message does not go back

## Status
- **Code + tests done; headless Chrome verified; Brave check owed.** Plan:
  `docs/plans/2026-11-05-reader-escape-back.md`.

## Causes (three)
1. A conversation keeps its Back in `.thread-top`; `kbBack()` only searched
   `.actions-bar`, fell through to `?page=inbox`, and spun no button.
2. Clicking the message body focuses the iframe's document, whose keydown never
   bubbles to the page, so `kbDispatch` saw nothing.
3. Brave/Chromium treats Esc as "stop loading": a navigation started inside the
   keydown is cancelled before the request leaves the browser. Spinner ran, no
   Network entry, `u` worked while Esc did not.

## Fix
- `kbBack()` checks `.actions-bar`, then `.thread-top`, then Inbox.
- `wireFrameKeys()` binds `keydown` on the inner document and calls
  `kbDispatch` (once per document, re-bound after a reload).
- `kbNavSoon()` defers the navigation by one task with `setTimeout(…, 0)`;
  `kbBack()` and `kbDiscard()` use it. Acknowledgement stays synchronous.

## Tests
- `tests/js.php` S97 (iframe forwarding), S98 (conversation Back, Esc and u),
  S99 (deferred navigation, Esc and Esc-to-discard).
- `tests/unit.php` source guards for all three.
- `php tests/run.php`: **3231 passed, 0 failed, 2 smoke skips**.
- Headless Chrome: reader and thread land on `?page=folder&folder=<folder>`;
  thread no longer requests `?page=inbox`.
- Owed: Brave cannot be automated for the accelerator; user must confirm.
