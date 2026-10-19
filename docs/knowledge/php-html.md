# PHP and HTML gotchas

## Includes are not idempotent via a guard
A `defined()`-guard **cannot** make an included file idempotent: unconditional top-level
`function` declarations are early-bound at compile time, so the guard is checked after the
declaration already exists. Use `require_once`.

## Heredocs as function arguments
A heredoc used as a *function argument* closes with the marker on its own line, then `);` on
the next line. Never `JS;` on the marker line.

## SQLite
- `db_open` must not cache the static PDO before migrating.
- SQLite ignores `busy_timeout` for a `journal_mode=WAL` switch.

## redirect() calls exit
A handler that redirects cannot be tested in-process. Push the rule into a pure function and
test that instead. This is why `folder_create_check` / `folder_delete_check` /
`folder_move_check` / `folder_empty_check` exist as pure gates returning
`ok|protected|invalid`.

## A GET form discards the action's query string
Any `?page=...` on a `method="GET"` form must also be a **hidden field**, or the route is
lost. (Search was dead because of this.)

## `: void` on a method used in a boolean guard
That is silently dead code. `markAsRead()` / `markAsReadUnreadByUid()` were `void`, so
`if ($ok) db_set_message_flags()` never ran and the list rendered stale. Methods used in a
guard return `bool`.

## The 12-line preamble
Read/reply/forward/download share a preamble. It was extracted into a pure
`message_target_from_request()`; the four `!$uid` guards and `redirect()` stay inline.

## `win` is not a global
`js_inline()` is an IIFE, so `win` is IIFE-local. `render_page` once emitted
`$.ready(function(){ win.__openOnboarding = true; })`, which threw a `ReferenceError` in a
DOMContentLoaded handler on **every** page. It sets `window.__openOnboarding` now, pinned by
two unit guards.
