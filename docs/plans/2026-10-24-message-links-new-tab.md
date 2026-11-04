---
status: in progress (code + tests done; manual browser pass not run)
---

# Open links in a message body in a new tab

## Problem
A link or "button" in a message body opens its URL inside the read iframe.
Causes:
1. `sanitize_html()` removes every `target` attribute (good: the sender must not choose).
2. The iframe sandbox is `allow-same-origin` only. Without `allow-popups`, the browser
   blocks a `target="_blank"` navigation, so the only possible navigation is the frame itself.

## Fix
1. `sanitize_html()`: after the attribute rebuild, on an `<a>` whose kept `href` is a real
   `http(s)://` URL, set `target="_blank" rel="noopener noreferrer"`. The app sets these,
   never the sender (sender `target`/`rel` are still dropped).
   - The check reads the href as `saveHTML()` writes it: libxml strips leading
     space/tab/CR/LF and %-escapes an inner tab (`ht&#9;tps:` -> relative URL). So only
     `ltrim(" \t\r\n")` then `^https?://`. `jav&#9;ascript:` never gets a target. This is
     load-bearing: `allow-popups-to-escape-sandbox` + `allow-same-origin` would let a
     `javascript:` popup run on the app origin.
   - No target on `#x`, empty, relative, `mailto:` or no-href links: they keep today's
     behaviour. A relative href resolves against the app URL, so it must not open an
     unsandboxed app tab.
   - `noreferrer`: the reader URL (`?page=read&uid=…`) must not leak to the sender's site.
2. The iframe sandbox becomes `allow-same-origin allow-popups allow-popups-to-escape-sandbox`.
   - `allow-popups` lets the click open a tab.
   - `-to-escape-sandbox` lets the new tab run normally (else the target site has no scripts).
   - `allow-scripts` stays off. The body still cannot run code, so it cannot open
     a popup without a user click.
   - `<form>`, `<button>`, `<area>`, `<base>` are unwrapped by the sanitizer. Do not add
     `allow-forms`.

## Tests (red first)
- unit: an `https://` link gets `target="_blank"` and `rel="noopener noreferrer"`.
- unit: a sender `target="_top"` / `rel="opener"` is replaced, never kept.
- unit: no target on `#x`, ` #x`, `""`, relative, `mailto:`, no-href, and on
  `jav&#9;ascript:`, `java&#10;script:`, `&#1;javascript:`.
- unit: `ht&#9;tps://` is written as `ht%09tps:` (relative) and gets NO target.
- unit: lines 2542 and 3385 assert the exact new sandbox string; the `allow-scripts`
  source guard stays.

## Docs
- `docs/knowledge/security.md`: the read iframe section.
- Comments at `index.php` ~286 and ~11337.
- README: no sandbox tokens listed, no change.

## Verification
- `just test` green.
- Manual: open a message with a link, click it, a new tab opens. (Not run by the agent.)
