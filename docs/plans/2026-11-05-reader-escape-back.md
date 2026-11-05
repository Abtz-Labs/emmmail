---
status: in progress (code + tests done; headless Chrome verified; Brave accelerator check owed)
---

# Esc from a message does not go back

## Problem

Pressing `Esc` in a message did not return to the folder. Three independent causes:

1. **The conversation page.** A conversation keeps one Back control in
   `.thread-top`; its per-message action bars carry none. `kbBack()` only looked
   inside `.actions-bar`, found no Back, and fell through to `?page=inbox`. So the
   only feedback was the chrome loading bar — never the Back spinner — and the
   request went to the slow Inbox path instead of the message's own folder.
2. **The message body iframe.** Clicking the body moves focus into the frame's
   own document, whose `keydown` never bubbles to the page, so `kbDispatch`
   never saw the key at all. This killed every reader shortcut, not only Esc.
3. **Chromium's Esc-to-stop-loading accelerator (Brave).** Even with the right
   target, a navigation started inside the Escape keydown is cancelled before
   the request leaves the browser. The spinner and the chrome bar ran, the
   Network tab stayed empty, and `u` (same code, no such default) worked.

## Fix

`js_inline()`:

- `kbBack()` looks for the Back control in `.actions-bar` first, then in
  `.thread-top`, before falling back to `?page=inbox`. The two-step queries stay
  flat because the js harness `matches()` has no descendant combinator.
- `wireFrameKeys(frame)` binds `keydown` on `frame.contentWindow.document`
  (legal because the sandbox has `allow-same-origin`), guarded by `try`/`catch`.
  It binds once per document (`frame._keyDoc`) and re-binds after a reload.
  It calls `kbDispatch`, which still owns the modal, editable and off-switch
  guards, so Cmd/Ctrl+C inside the body stays a copy and an open dialog keeps Esc.
- `kbNavSoon(href)` starts a navigation with `setTimeout(…, 0)` instead of inside
  the key event, so the browser's own Esc action is handled first. `kbBack()` and
  `kbDiscard()` use it; the spinner and the chrome bar still run synchronously.

## Tests

- `tests/js.php` S97: a key dispatched inside the body reaches the page; Esc goes
  back and spins the Back link; Cmd+C does not navigate; Esc with a dialog open
  does not leave the reader.
- `tests/js.php` S98: a conversation page whose Back is in `.thread-top` and whose
  `.actions-bar` has none. Esc and `u` go to the conversation's own folder and
  spin that Back control, not `?page=inbox`.
- `tests/js.php` S99: Esc and Esc-to-discard do not navigate inside the key event;
  they acknowledge at once and navigate on the next task.
- `tests/unit.php`: source guards for the forwarding, the once-per-document
  check, the `.thread-top` lookup and `kbNavSoon`.

## Verification

- `php tests/run.php` green: 3231 passed, 0 failed, 2 smoke skips.
- Headless Chrome against a local instance and the wire-level IMAP stub:
  - single reader, focus in the page: Esc → `?page=folder&folder=INBOX`;
  - single reader, click the message body, Esc → `?page=folder&folder=INBOX`;
  - conversation page, Esc → `?page=folder&folder=INBOX` (was `?page=inbox`),
    with a request to the folder URL and none to `?page=inbox`;
  - `r` from the body → Reply; Cmd+C from the body stays on the reader;
  - no page errors.
- Owed: confirm in Brave that Esc now reaches the network. The accelerator does
  not run for synthetic key events, so automation cannot cover it.
