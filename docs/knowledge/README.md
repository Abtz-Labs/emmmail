# Knowledge Index

Durable, topic-scoped knowledge for this codebase. Each file is a hard-won invariant or a
platform fact, with the reason it exists. `docs/memory.md` holds only what is true *this
session* (plan status, open items); everything here is true regardless of session.

Read the relevant file before touching the matching area.

| File | Covers |
|---|---|
| [imap.md](imap.md) | ext-imap / c-client 2007f traps, the IMAP connection probe, sync and reconcile invariants, folder counts, `\Noselect`, per-message IMAP rules |
| [php-html.md](php-html.md) | PHP and HTML gotchas: idempotent includes, heredocs, SQLite migration, `redirect()`, GET forms, void returns |
| [security.md](security.md) | The CSP is not a barrier, `sanitize_html`/`sanitize_css`, the read iframe sandbox, remote images, server-side gates, Bcc in transit |
| [frontend.md](frontend.md) | The `$` toolkit and its three banned APIs, the round-trip feedback vocabulary, JS/DOM contracts, optimistic actions, the conversation reader's active-message seam, the JS test stub, the poll chain |
| [css-ui.md](css-ui.md) | Dark tokens in three places, specificity and source-order traps, tooltips, toolbars, composer layout, the reader toolbar |
| [testing.md](testing.md) | TDD loop, source-level guards, why a crash is not a failing test, terminal-browser verification, what no harness can prove |
| [composing.md](composing.md) | The Markdown composer, outbound threading, the conversation view, drafts and attachments, undo send, the signature, POST overflow |
| [environment.md](environment.md) | The `imap` extension install, the CA bundle, Migadu hosts, TCP timeouts |

## Rules that follow from the index
- **A JS/DOM contract needs a PHP-side test too.** The js suite builds its own fixture and
  cannot see the real markup.
- **A crash is not a failing test.** A suite that dies mid-run is one red line, not one red
  test, and it hides everything after it.
- **Markup exists for the JS, not the other way round.** The js stub's `matches()` has no
  descendant combinator and no `:not()`, so selectors are written flat or two-step.
- **A browser global the app was handed is reached through the object it was handed in.**
  `win.Notification`, `doc.hidden`, `doc.hasFocus()`, `win.isSecureContext`: a bare
  `Notification` works in a browser and throws in the stub, which is the only place it
  is caught. `assert_no_bare_global()` in `tests/unit.php` is the guard.
- **When JS toggles a class, the CSS must name the element the JS touches.**
- **Lifting a documented limitation is test + README + plan, together.**
