# Testing

## The loop
TDD: tests first, then implementation. `just test` == `php tests/run.php`. Suites: `unit`,
`js`, `imap`, `smoke`. One suite with `php tests/<name>.php`. **There is no subset
runner:** `tests/run.php` ignores `$argv`, so `php tests/run.php unit imap` runs all
four suites, exactly as the bare command does.

Files starting with `_` are **support, not suites**: `_harness.php` (bootstrap + assertions),
`_imap_stub.php` (a wire-level IMAP server). `smoke` is live and skips without credentials.
`js` skips without `node`.

## A crash is not a failing test
`makeEl()` in `tests/js.php` once had `querySelectorAll` / `matches` / `closest` but no
`querySelector`, and the compose submit handler calls `form.querySelector(...)`. The suite
**crashed** at S26, and the harness counted the crash as *one* failure — so S26-S31 looked like
a single red line rather than a dead half-suite, hiding roughly 190 tests.

**When a suite count jumps by hundreds, suspect a stub gap, not new coverage.** Same family as
the old missing `hasAttribute()`.

## Source-level guards
This repo already uses them, and they are the only way to test some things:

- `no dead code` — every public `MailImap` method is called or tested, and every `define()`d
  constant is read. `MIN_TLS_VERSION` was dead and is gone.
- `css_inline() declares no rule twice` — no two top-level rules may share a selector **and** an
  identical body. Deliberately narrow: `.mail-avatar` is declared in two sections with
  *different* bodies on purpose, and a wider version of this test would fail on it.
- The read iframe's `allow-scripts` must stay off (see [security.md](security.md)).

## The js stub is not a browser
- The fetch stub used to throw **synchronously** on network failure, which no real fetch does.
  It returns a rejected promise now, so `.catch()` branches are genuinely exercised.
- `matches()` has **no descendant combinator and no `:not()`**. Selectors must be flat or
  two-step, and the markup must carry the hooks the JS needs.
- `doc.dispatch(type, init)` reaches only handlers registered **on the document**. A binding
  delegated off `doc` is invisible to the harness, so a click test dispatches on the element
  the handler was bound to (`doc.dispatch('keydown', …)`, `row.dispatch('click')`).
- `el.dispatch()` bubbles through `parentNode`, and `removeChild()` does **not** prune the
  document's flat `_all` list, so a detached node still matches `querySelector`. Assert on
  `parentNode` when the question is "is it gone".
- `win.location.href` is a real property with a write counter (`win._hrefs`), because a
  refresh is `win.location.href = win.location.href` and a plain object would hide it.
- `fakeTimers(win)` swaps `win.setTimeout`/`win.clearTimeout` for a queue and moves
  `Date.now` with it, so two hours of polling runs in microseconds. `tick()` fires everything
  due on the way and settles after each callback; `pending()` counts the queue. Two hours
  idle then cost 120 POSTs, not 120 minutes of wall clock.
- A promise chain started by a synchronous call settles only *after* the test's next
  `await`. `doc.dispatch('visibilitychange')` then `tick(60_000)` sees an empty queue and
  misses the tick the chain has not scheduled yet — `await settle()` in between.
- There is no `Selection`, so `mdMatchRule()` is pure and tested; the DOM side is checked in
  the terminal browser.
- The JS suite builds its own fixture, so **it cannot see the real PHP markup**. A JS/DOM
  contract needs a PHP-side test too.
- Node has no `Notification` and no `localStorage`, so a bare global is a crash in the
  harness and a silent pass in a browser. `makeNotification()` and `storageWith()` stub them
  on `win`, and `assert_no_bare_global()` in `tests/unit.php` keeps them off the bare path.

## Terminal-browser verification
Some things only a real engine answers:

- A scripted `el.click()` does **not** invalidate `input:checked + .sibling` styles. Verify
  toggles with a real dispatched click, not `eval`.
- Chrome's `formatBlock` nests a `<div>` inside a `<pre>`; exiting means insertHTML with the
  caret placed after the `<pre>`.
- Chrome's `outdent` copies the computed colour into `<font color>`, so the CSS neutralises
  both `[style]` and `font`.
- A formatting trigger costs 2 undo steps, and CDP `Meta+z` does not reach native undo.

## What no harness can prove
- **No live browser pass has been run** for the Markdown composer outside Chromium, or for the
  keyboard layer's real `showModal` focus, native `<select>` Enter in the move dialog, or
  pagination cursor restore. `smoke` skips without IMAP credentials, so the instant
  folder-switch click-through is unverified in a live browser too. These are tracked as
  deferred in `docs/memory.md`.
