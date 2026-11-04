---
status: done
---

# Resolve the login-lock merge conflict

## Problem
Commit `b44f2c6` holds unresolved stash-pop markers. Two implementations of one feature
collided: Upstream (`2026-10-19-server-lock-and-allowed-domains`: `server_lock()`,
`login_gate()`, `EMMMAIL_PROVIDER`) and Stashed (`2026-10-22-deploy-and-login-lock`:
`forced_mail_preset()`, `login_domain_error()`, `EMMMAIL_FORCE_PRESET`). `index.php` does not
parse, and `email_domain_allowed()` is declared twice.

## Decision (user)
Keep Upstream. Port one Stashed feature: the email `placeholder="you@<first domain>"` hint.
Production moves from `EMMMAIL_FORCE_PRESET=migadu` to `EMMMAIL_PROVIDER=migadu`.

## Facts
Every `index.php` and `tests/unit.php` hunk in `b44f2c6` is lock code, so `0459528` is the
Upstream side exactly. `tests/js.php` S92 duplicates the Upstream S69 (e) test ("a locked
submit keeps the remembered preset and servers"), so `tests/js.php` returns to `0459528` too.

## Steps
| # | Step | Verify |
|---|---|---|
| 1 | `index.php`, `tests/unit.php`, `tests/js.php` := `git show 0459528:<file>`; re-apply the `thread_folder_is_member($folder)` line. `tests/imap.php` untouched | `php -l`; `grep 'thread_folder_is_member($folder) &&'`; `just test` green |
| 2 | Red test in `tests/unit.php`: `EMMMAIL_ALLOWED_DOMAINS=@Abtz.CO, example.org` -> `placeholder="you@abtz.co"`; unset -> no placeholder | the test fails |
| 3 | `view_login()`: add the placeholder from `allowed_domains()[0]` | the test passes |
| 4 | Docs: README (drop the `FORCE_PRESET` row and the duplicate `ALLOWED_DOMAINS` row; the hint in the Allowed domains bullet), `knowledge/security.md` (the hint), `knowledge/environment.md` (`EMMMAIL_PROVIDER=migadu`), the 2026-10-22 plan (`EMMMAIL_FORCE_PRESET` -> `EMMMAIL_PROVIDER`), `memory.md` (both markers, the Blocker note) | `grep -rniE 'force_preset\|forced.preset\|login_domain_error\|login_allowed_domains'` hits only plans and the intentional "FORCE_PRESET is gone" warnings; no markers |
| 5 | `just test` | all green |

## Deploy precondition
`bare.config.json` is not in the working tree. Its post-script must write
`EMMMAIL_PROVIDER=migadu`, not `EMMMAIL_FORCE_PRESET`, **before** this code goes live:
Upstream ignores `EMMMAIL_FORCE_PRESET`, so a stale env would show the server picker again.
Verify on the live login page that `id="login-preset"` is absent.
