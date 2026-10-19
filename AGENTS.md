# AGENTS.md

Guidance for agents working in this repository. This file is the **index**; the detail lives in
the files it points to. Read the relevant one before you touch the matching area.

## The project in one line
A one-file, Gmail-inspired webmail client (IMAP/SMTP), mobile-first. PHP 8.1+, ext-imap, raw
stream sockets for SMTP, vanilla JS, SQLite. Single `index.php`, routes via `?page=<page>`.

## Where things are
| Path | What it is |
|---|---|
| `index.php` | The entire application. Routing, IMAP/SMTP, DB, views, CSS, JS. |
| `README.md` | The route list, the settings, and the documented limitations. |
| `docs/knowledge/` | Durable invariants and platform facts, by topic. **Start at the [index](docs/knowledge/README.md).** |
| `docs/memory.md` | Session state only: plan status and open items. Keep under 150 lines. |
| `docs/plans/<date>-<slug>.md` | One plan per feature, with a `status:` header. `pending`, `in progress` or `done`. |
| `tests/` | `run.php` plus the suites `unit`, `js`, `imap`, `smoke`. `_`-prefixed files are support. |
| `justfile` | `just test`. Check it before running anything. |

## Knowledge index
| File | Covers |
|---|---|
| [docs/knowledge/README.md](docs/knowledge/README.md) | The index, and the four rules that follow from it |
| [docs/knowledge/imap.md](docs/knowledge/imap.md) | ext-imap / c-client 2007f traps, the connection probe, sync and reconcile, folder counts, `\Noselect` |
| [docs/knowledge/php-html.md](docs/knowledge/php-html.md) | Idempotent includes, heredocs, SQLite migration, `redirect()`, GET forms, void returns |
| [docs/knowledge/security.md](docs/knowledge/security.md) | The CSP is not a barrier, `sanitize_html`/`sanitize_css`, the iframe sandbox, remote images, server-side gates, Bcc |
| [docs/knowledge/frontend.md](docs/knowledge/frontend.md) | The `$` toolkit and its three banned APIs, round-trip feedback, JS/DOM contracts, optimistic actions, the conversation reader seam, the js stub |
| [docs/knowledge/css-ui.md](docs/knowledge/css-ui.md) | Dark tokens in three places, specificity traps, tooltips, toolbars, composer layout |
| [docs/knowledge/testing.md](docs/knowledge/testing.md) | TDD loop, source-level guards, why a crash is not a failing test, what no harness can prove |
| [docs/knowledge/composing.md](docs/knowledge/composing.md) | The Markdown composer, outbound threading, the conversation view, drafts and attachments, undo send, the signature, POST overflow |
| [docs/knowledge/environment.md](docs/knowledge/environment.md) | The `imap` extension install, the CA bundle, Migadu hosts, TCP timeouts |

## How to work here
- **TDD is imperative.** Plan, review, red tests, code, review, green tests.
- **Surgical edits only.** No collateral changes. Every line traces to the request.
- **Match the existing style**, including the guard conventions already in the suite.
- **Never remove a layer without its partner.** The folder `urlencode()`/`urldecode()` pair is
  the standing example.
- **Lifting a documented limitation is test + README + plan, together.**
- **Keep `docs/memory.md` lean.** Durable knowledge moves to `docs/knowledge/`; memory.md keeps
  only what is true this session. It has a 150-line budget.
- **Keep each plan's `status:` header honest** — code complete but the manual browser pass not
  run is *not* the same as verified.
- No git commands. Ask before destructive changes.
