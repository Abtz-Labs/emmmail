---
status: in progress (code + tests done; manual browser pass not run)
---

# On-device message translation (Chrome Translator API)

## Goal

Read a message in a language you do not master, without sending the body to a
server and without an API key.

This is the zero-cost prototype. The longer server-side path is
[2026-11-05-server-side-translation.md](2026-11-05-server-side-translation.md).

## Platform facts

- Chrome 138+ and Edge 148+ expose `Translator` and `LanguageDetector`. Other
  Chromium browsers (Brave, Vivaldi) may not ship the models.
- Both work in the top-level window and same-origin iframes. This app is
  top-level, so no Permissions-Policy change is needed.
- They need a secure context (HTTPS or localhost).
- Chrome's docs say **desktop only**. Mobile Chrome, Safari and Firefox do not
  expose them.
- `Translator.create()` needs user activation, so it runs from the click.
- The first use of a language pair downloads a pack; `monitor` reports
  `downloadprogress`.
- The API is text-only and processes requests sequentially.

## Design

### Markup

`view_message_body()` renders the bar above the body, so both the single reader
and every message in a conversation get one:

- `Translate to` + a `<select class="translate-lang">` with a small BCP 47 list.
- `[data-translate]` button, labelled `Translate`.
- `[data-translate-show]` button, labelled `Show original`, hidden at first.
- `.translate-status` with `aria-live="polite"`.

The bar ships with `.hidden`. JavaScript removes it only when **both**
`win.Translator` and `win.LanguageDetector` exist. A browser without the API
must never show a dead control.

### JavaScript

At boot, for each `.translate-bar`:

1. Find the sibling `iframe.srcdoc` through the bar's parent.
2. Feature-detect `win.Translator` and `win.LanguageDetector`; stop if either is
   missing.
3. Set the select to `navigator.language` when that base code is an option, else
   English.
4. Unhide the bar and bind the two buttons.

On **Translate**:

1. Read the body through `frame.contentWindow.document.body` (the same access
   `fitOne()` already uses; `allow-same-origin` makes it legal).
2. Use `innerText` (fallback `textContent`). Cap at 20 000 characters and say so.
3. `LanguageDetector.create()` → `detect(text)` → destroy the detector.
4. Stop if the detected source equals the target.
5. `Translator.availability({sourceLanguage, targetLanguage})`. `unavailable`
   is an error; `downloadable` and `downloading` go to `create()`.
6. `Translator.create()` with a `downloadprogress` listener that updates the
   status.
7. `translator.translate(text)`, then destroy the translator.
8. Write the result with `body.textContent = out` (never `innerHTML`: the
   translation is text, not markup), set `white-space: pre-wrap`, `dir="auto"`,
   and call `fitOne(frame)`.
9. Hide Translate, reveal Show original, clear the busy state.

On **Show original**: restore the saved body HTML, inline `style` and `dir`, call
`fitOne(frame)`, reveal Translate, hide Show original. Do not reassign `srcdoc`: that
reloads the frame and loses the DOM restore and the `ResizeObserver`.

Busy state uses `markBusy()` / `clearBusy()`, the only entry points. Every error
path clears it.

### Why `textContent`

The translated text is untrusted output. Writing it as text means an injected
`<img onerror>` in the translation is displayed as characters, not executed.
The email iframe already has no `allow-scripts`.

## Tests (red first)

### `tests/unit.php`

- `view_read()` ships `.translate-bar.hidden`, the select, both buttons and the
  language icon.
- The language list contains `en`, `pt`, `ja` and `zh`.
- `view_thread()` gets one bar per message (it lives in `view_message_body()`).
- `.translate-bar` and `.translate-status.error` are in `css_inline()`.
- `js_inline()` reads `win.Translator` / `win.LanguageDetector`, listens for
  `downloadprogress`, and writes the result with `textContent`.
- `assert_no_bare_global('Translator')` and
  `assert_no_bare_global('LanguageDetector')`.

### `tests/js.php` S96

- Without the APIs the bar stays hidden.
- With them the bar appears; `pt-BR` selects `pt`.
- A click detects `ja`, asks for `ja` → `pt`, replaces the body with the
  translation, hides Translate and reveals Show original.
- Show original restores the `srcdoc` and body HTML and swaps the buttons back.
- `availability: 'unavailable'` keeps the original and explains the pair.
- A rejected `create()` keeps the original, reports the reason, and clears busy.
- Source equal to target makes no translation call.
- An empty body makes no API call.
- A 20,001-character body sends 20,000 characters and says the message was cut.
- A `QuotaExceededError` is explained in plain words.
- Show original restores the body's inline style and `dir`, and the language
  picker locks while a translation is shown.

## Limits (documented, not bugs)

1. Desktop Chrome/Edge only. The app is mobile-first; this does not serve phone
   use.
2. The first use of a pair downloads a language pack. The detector's first
   download can also outlive the click's activation window; the bar then asks
   for a second click instead of showing a raw DOMException.
3. Plain text replaces the formatted body, so links, tables and images are lost.
   Show original restores them.
4. The translation is not cached; a reload shows the original.
5. The subject is not translated.
6. A very long message is truncated at 20 000 characters.
7. No translation in a conversation member that has not been opened.

## Docs

- `README.md`: feature bullet + a short `Translation` section.
- `docs/knowledge/frontend.md`: the bar contract, the iframe access, the
  `textContent` rule, and the desktop-only limit.
- `docs/memory/2026-11-05-chrome-translation.md`: plan status, browser pass owed.

## Verification

- `php tests/run.php` green.
- Manual browser pass owed: Chrome desktop, a Japanese message, first-use pack
  download, `Show original`, light and dark themes, and a thread message.
