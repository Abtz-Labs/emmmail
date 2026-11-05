# 2026-11-05 — Chrome on-device translation

## Status
- **Code complete; browser pass NOT run.** Plan:
  `docs/plans/2026-11-05-chrome-on-device-translation.md`.
- Server-side fallback written as a **pending** plan:
  `docs/plans/2026-11-05-server-side-translation.md`.

## What shipped
- `view_message_body()` renders `.translate-bar.hidden` for the reader and every
  conversation message. Hidden unless `win.Translator` + `win.LanguageDetector`
  exist (Chrome/Edge desktop only).
- JS detects the source, translates `innerText`, writes `body.textContent`, and
  keeps `frame._xlOriginal` for "Show original".
- No server call, key, schema change or disk cache. 20,000-character cap.
- `js_inline()` guards: `Translator` and `LanguageDetector` are `win.*` only.

## Tests
- `tests/unit.php`: bar markup, language list, per-thread bar, CSS, JS source
  guards, bare-global guards.
- `tests/js.php` S96/S96b–h: hidden without either API, happy path, style and
  direction restore, unavailable pair, failed download, same language, empty
  body, 20k cap, quota error, activation retry.
- `php tests/run.php`: **3280 passed, 0 failed, 2 smoke skips**.

## Owed
- Manual Chrome desktop pass: Japanese message, first-use language-pack download,
  Show original, light/dark, thread member.
- Server-side MVP implementation per the pending plan.
