# Remote images: blocked by default, per-message CTA, sender whitelist

status: done

## Why

Emmmail loads every remote image immediately, which hands a read receipt and an
IP address to every tracking pixel. Other clients default this off. Now that
`sanitize_html()` keeps CSS, `url()` is a second channel that must be covered.

## Decisions

- **Default OFF.** Privacy-first, matching the Migadu behaviour in the screenshot.
- **The whitelist stores the exact sender address**, not the domain (your call).
- **Blocked images keep their element**, with `src` moved to `data-blocked-src`,
  so `width`/`height` still hold the layout open instead of collapsing it.
- **Two channels, one gate**: `<img src>` / `background=` attributes AND
  `url(...)` inside style attributes and `<style>` blocks.

## Steps

| # | Change | Verification |
|---|---|---|
| 1 | `images_allowed(string $sender, bool $onByDefault, string $whitelist): bool` — pure, case-insensitive, exact address | unit: each branch |
| 2 | `sanitize_html($html, bool $blockRemote = false, ?int &$blocked = null)` — moves remote `src` aside, strips remote `url()` and `background=`, counts what it blocked | unit: both channels, count, and off-by-default unchanged |
| 3 | `show_remote_images` setting (default `0`) + `image_whitelist` in the existing per-user key/value table; settings form gains the toggle | unit: handler persists both |
| 4 | Reader CTA when `$blocked > 0`: **Show images** (this message only) and **Always trust `<sender>`** | unit: markup, and absent when nothing was blocked |
| 5 | `?page=read&images=1` shows them once, without touching the whitelist | unit: pure request decision |
| 6 | `?page=trustSender` (POST, CSRF) appends the address and returns to the message | unit: route + gate |
| 7 | README + memory | read back |

## Risks

- A message whose only content is a tracked image looks empty. The CTA bar has to
  be obvious, not a footnote.
- `cid:` parts are already served by this app, so they are not third-party and
  must not be blocked. They are separately not implemented, so nothing regresses.

## Outcome

Verified in a browser, inside the body iframe:

| | Blocked | Trusted |
|---|---|---|
| `<img>` with loadable `src` | 0 | 2 |
| parked on `data-blocked-src` | 2 | 0 |
| CSS `background-image` | `none` | loads |
| CSS `background-color` | kept | kept |

- `images_allowed()` / `url_is_remote()` / `css_strip_remote_urls()` are pure.
- `sanitize_html($html, $blockRemote = false, &$blocked = null)` — default off, so
  every existing caller is unchanged and blocking is opt-in at the call site.
- `?page=read&images=1` reveals for that view only; `?page=trustSender` appends the
  exact address after `FILTER_VALIDATE_EMAIL`.
- A `tabler-photo` sprite symbol was missing; `icon('photo')` would have rendered an
  invisible svg. A test now asserts the symbol exists.
