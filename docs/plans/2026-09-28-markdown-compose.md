# Markdown compose with live rendering

status: done

## Goal

The composer renders Markdown as the user types (contenteditable + input rules),
with zero dependencies. Markdown is the wire format: the server renders it to the
text/html part and sends the source as the text/plain part.

## Decisions (settled 2026-09-28)

- No CDN, no vendored library. CSP (`script-src 'self' 'unsafe-inline'`) stays as is.
  Consequence: the CSP is NOT a script barrier, so the renderer output must be safe
  by construction (the editor lives in the main DOM, not the sandboxed iframe).
- The value posted is Markdown in `<textarea name="body">`. The server never accepts
  HTML from the client. No new endpoint (no preview endpoint in v1).
- text/plain part = the Markdown source, as typed.
- Links `[t](url)` and remote images `![alt](url)` are in scope.
- Progressive enhancement: with JS off, the textarea works and the same renderer
  applies.

## Review outcome (4 reviewers, 2026-09-28)

Security, editor feasibility, codebase fit, email interop. Changes folded in below.
The ones that change the design:

1. **Renderer is a one-pass tokenizer, not a regex cascade.** A later regex can
   rewrite markup an earlier one emitted (autolink inside a generated `href`) and
   break out of the attribute -> XSS in the main DOM. Text runs are `h()`d on
   output, each attribute value exactly once.
2. **Quoted and draft bodies are untrusted.** A quoted `![](https://tracker)` would
   load on Reply (bypasses `images_allowed()`) and reach every recipient;
   `![](https://<host>/?page=logout)` is a GET CSRF. The whole quoted body goes
   through `md_escape()`.
3. **Paste stays literal in v1.** Rendering pasted Markdown needs a JS parser.
4. **Serializer writes the textarea only after a real edit** (dirty flag), and
   escapes only where a rule would fire. Otherwise an untouched draft is rewritten
   and the text part fills with `\*`.
5. **`defaultParagraphSeparator = 'div'`**: one Enter = new line, blank line = new
   paragraph. Matches Gmail and the "single newline -> `<br>`" rule. (`p` gave a gap
   on every Enter.)
6. **Nested blockquotes are required** (`> >` is normal in plain-text threads).

## Data flow

```
contenteditable --(JS: DOM -> Markdown, on input when dirty + on submit)--> textarea
  --POST--> md_to_html($body) -> text/html part (wrapped document, inline styles)
            $body (CRLF)       -> text/plain part
Drafts/reply/forward: body (Markdown) --md_to_html--> editor's initial HTML
```

## Markdown subset (renderer, escaper and serializer must agree)

Block:
- `#`..`######` + space -> h1..h6. `#hashtag` stays literal.
- `-`, `*`, `•` + space -> ul. `N.` + space -> ol with `start="N"`. An ol may
  interrupt a paragraph only when it starts at `1.` (`1986. was a year` stays
  literal). Flat lists only (see L1/L2).
- `>` + space -> blockquote, recursive (`> >` nests).
- ``` fence -> `pre>code`, content verbatim, escaped.
- `---` alone on a line -> hr (also `***`, `___`, iOS `—-`).
- Line endings normalised to `\n` first. **One source line = one `<div>`**; a
  blank line = `<div><br></div>` (Gmail's own model, and what the editor makes
  with `defaultParagraphSeparator='div'`, so the round trip is exact). Inline
  rules work per line: emphasis never spans lines. Trailing blank lines dropped.
- List item continuation: an indented non-item line joins the item with `<br>`.
  An unindented line ends the list. A blank line ends the list.

Inline (tokenised in this order: code spans, autolinks/links/images, then emphasis):
- `` `x` `` code, `**x**` strong, `*x*` / `_x_` em, `~~x~~` -> `<s>` (a single `~`, as in "~5 min", stays literal)
- `[text](url)` -> a; `![alt](url)` -> img; bare `https?://` -> autolink with
  balanced parens (`Foo_(bar)`), trailing `.,;:!?` stripped.
- `\` escapes `\ * _ ` [ ] ( ) # - > ! ~`. `C:\path`, `:-*` stay literal.
- Opening `*`/`_` needs a non-space after it; intra-word `_` never emphasises
  (`snake_case_name`, `2 * 3 * 4` stay literal).

Safety (renderer):
- Strip NUL. `h()` text runs on output; attributes escaped once.
- URL check on the RAW capture: reject C0 chars, whitespace, backslash; then a
  positive match `^(https?|mailto):` (links) / `^https:` (images), case-insensitive.
  Rejects `//evil`, relative, `java\tscript:`, `\x01javascript:`. Failing URL ->
  literal text.
- Only attributes `href`, `src`, `alt`, `start`, and constant `style` strings. No
  `class`/`id` (delegated app handlers match on classes).
- PCRE failure (`preg_last_error()`) -> fall back to `nl2br(h($md))`, never `''`.

Outgoing HTML:
- `<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>` + a `div` with
  `font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;
  line-height:1.5` (Outlook falls back to Times otherwise).
- Inline styles: `p,ul,ol` `margin:0 0 1em`; lists `padding-left:1.5em`; h1-h6
  explicit `font-size` 1.4em..1em + `margin:1em 0 .5em`; blockquote
  `margin:0 0 1em;padding-left:1em;border-left:3px solid #ccc`; pre
  `white-space:pre-wrap;word-wrap:break-word;font-family:Consolas,Menlo,monospace`;
  img `max-width:100%;height:auto`.
- **No text or background colours** (unreadable under recipient dark mode).

## Steps

Baseline: `just test` = 1264 passed, 0 failed, 2 skipped.
Each step: red tests -> code -> green.

0. **Spike (throwaway, terminal-browser).** In Chrome, Safari, Firefox: `formatBlock`
   with `'<h1>'`/`'<blockquote>'`/`'<pre>'`, `insertOrderedList`, `insertHTML` for
   code, `execCommand('delete')` on a selection, and the undo stack after each.
   Record what fails before writing any rule. Nothing from the spike is kept.
   **Result (Chromium via terminal-browser, 2026-09-28; Safari/Firefox still to
   check by hand in step 8):** `formatBlock` takes `'<h1>'` and `'h1'`;
   blockquote, pre, both lists and hr work; each trigger costs **2 undo steps**
   (delete marker, format). Enter on an empty `li` exits the list natively.
   `insertHTML('<code>x</code>\u200B')` puts the next typed char outside the
   code. `bold` twice stops bold. `defaultParagraphSeparator='div'` gives
   `<div>a</div><div>b</div>`. Typed spaces arrive as `&nbsp;`. Backspace-merge of
   `h1` into a `div` gave plain text (no styled span) in this simple case.
1. **`md_to_html(string $md): string` and `md_escape(string $text): string`**, pure,
   in `[3] Security & text helpers` next to `sanitize_html()`.
   Tests (`tests/unit.php`): every rule above; escaping (`<b>`, `a < b`, `&`);
   every URL bypass listed; the autolink-inside-href breakout; strict allowlist
   assertion (parse the output with DOMDocument: only allowed tags, only allowed
   attributes, only allowed schemes) over hostile fixtures; `md_escape($x)` renders
   back to `$x` as plain text; nested quotes; `start="N"`; 1 MB pathological input
   does not return `''`.
2. **Send path.** Trailing `?string $textBody = null` on `smtp_build_raw_message()`
   (null keeps today's `strip_tags` result). `handle_send` / `handle_save_draft`
   call it with `textBody: $body` (CRLF-normalised, no `strip_tags`) and
   `md_to_html($body)`. Tests decode the base64 parts: HTML has `<strong>` and
   `<br>`; text part is the exact source (`a <b> c`, `&amp;` survive).
3. **Quoting.** Use `body_text` as-is when present; `strip_html_for_quote()` +
   `html_entity_decode` only for HTML-only mail (today it strips `<ann@x.io>` from
   plain text). Then `md_escape()` the whole quoted body.
   Reply: escaped `On <date>, <from> wrote:` line, then `> `-prefixed body.
   Forward: escaped header block + escaped body, no `>`.
   Tests: quoted `![](…)` gives no `<img`; quoted `* a`, `1. b`, `# t` stay literal;
   existing `> x` nests one level deeper.
4. **JS serializer DOM -> Markdown** (pure function over `nodeType`, `nodeName`,
   `nodeValue`, `childNodes`, `getAttribute`; exposed on `win`). Maps `div` like a
   line, `b`/`i` like `strong`/`em`; ignores `span`/`style`; drops unknown elements
   (keeps text) and failing URLs; U+00A0 -> space; strips U+200B; escapes only
   where a rule would fire; percent-encodes `( ) space` in URLs.
   Tests (`tests/js.php`): plain object trees; round trip via PHP: DOMDocument
   parses `md_to_html()` output into a JSON tree in a temp file (path via env, like
   `APP_JS_PATH`). Canonical Markdown round-trips byte for byte; other Markdown is
   stable after one pass. Do not extend the DOM stub.
5. **Composer markup + wiring** (with 4, never without it: an editor that does not
   sync loses the body). `view_compose()` renders
   `<div class="md-editor hidden" contenteditable="true">` with
   `md_to_html(body)` and a Markdown placeholder hint. Textarea keeps `autofocus`
   (unit.php:2103-2106). JS, inside `$('.md-editor').each` and only if
   `doc.execCommand` exists: unhide editor, hide textarea, `.focus()` the editor if
   the textarea had focus (caret at start), find the textarea via `closest('form')`.
   Sync into the existing compose submit handler (~index.php:5755; also fires for
   Save draft). CSS: `.md-editor [style]{font:inherit!important;
   color:inherit!important;background:none!important}`; neutral colours, any new
   token in all three theme places. PHP-side test for the DOM contract.
6. **Input rules.** Pure `matchRule(textBeforeCaret)` -> `{rule, markerLen}`, tested
   in Node. DOM side: run on `input` (skip while `isComposing`) and on
   `compositionend`; idempotent; re-entry guard (execCommand fires `input`). Remove
   the marker with `execCommand('delete')` on a selected range, never Range edits.
   Block: `formatBlock('<hN>'|'<blockquote>'|'<pre>')`, list commands,
   `insertHorizontalRule`. Inline: `bold`/`italic` (toggle off after), code via
   `insertHTML('<code>x</code>\u200B')`. `[t](url)` -> `createLink`;
   `![a](url)` -> `insertImage` + alt; both through the same URL allowlist as PHP.
7. **Keys, paste, drop.** Enter on an empty `li` is native (no code). Enter in
   blockquote exits on an empty line; in `pre` `insertLineBreak`, exit on an empty
   last line. Backspace right after a trigger restores the literal marker.
   ⌘B/⌘I native; ⌘U blocked (Markdown cannot show `<u>`); no ⌘K in v1 (needs a
   dialog; `prompt()` is banned). `beforeinput` for `insertFromPaste`,
   `insertFromDrop`, `insertReplacementText` with non-text payloads:
   `preventDefault` + `insertText(plain)`. Never `insertHTML` with user content.
8. **Browser verification** (terminal-browser): each trigger; one ⌘Z after an
   auto-format restores the literal text (two undo steps are acceptable; record
   it); Enter/Backspace edges; paste and drop from a rich source; untouched draft
   reopen+send keeps the exact source; reply to a mail with `![](…)` loads nothing;
   Safari, Chrome, Firefox, one Android and one iOS keyboard; JS off.
9. **Docs.** README: feature list (README.md:26-29) + a new "Composer" section
   (syntax, ⌘B/⌘I, and a "Limitations" subsection for L1-L10). A small `data-tip`
   Markdown hint in the composer. memory.md.
   Tests for L1-L10 are written in the step that owns the behaviour (L1-L6 in
   step 1, L3/L7/L8 in steps 6-7), not deferred to here.

## Known consequences

- Remote images the user embeds are fetched by the editor and by recipients; many
  clients block them by default, and they can raise spam scores. The user's own
  Sent copy blocks them under the existing remote-image rule. Inline CID images are
  future work.
- `execCommand` is "deprecated" but has no replacement and keeps native undo.
- Sent/Drafts list snippets show raw Markdown (`**`). Accepted.
- A non-BFCache reload restores the textarea, but the editor shows the server HTML
  and wins on submit. Accepted for v1.
- Outlook desktop ignores `max-width` on images. Accepted.
- A nonce-based CSP would turn a renderer bug from script execution into a markup
  bug. Out of scope; noted in memory.md already.

## v1 limitations (decided; documented AND pinned by tests)

Each limitation is a decision, not a gap. Each has a regression test in a group
named `markdown v1 limitations`, so a change of behaviour fails loudly and the
failure names the decision. If a later version lifts a limitation, it updates the
test, this table and the README section together.

| # | Limitation | Why | Test (suite) |
|---|---|---|---|
| L1 | Flat lists only. Indented `  - x` / `   1. x` render as items of the SAME list, one level. | Nesting needs a tree in renderer, escaper and serializer; Tab moves focus in a form. Repeated-marker shortcuts (`--`, `**`) were considered and rejected: they clash with the `-- ` signature line, iOS `--`->`—`, the hr rule and bold. | `md_to_html("- a\n  - b")` has one `<ul>`, two `<li>`, no nested `<ul>` (unit) |
| L2 | `-- x`, `** x`, `•• x` are NOT list markers; they stay literal text. | See L1. | unit: no `<li>`, no `<strong>`, text kept |
| L3 | Tab is not intercepted in the editor; it moves focus. | Keyboard users must be able to leave the editor. | js: keydown Tab on `.md-editor` is not `preventDefault`ed |
| L4 | No raw HTML: `<b>x</b>` is sent as the literal text. | The renderer escapes first; the server never trusts client HTML. | unit: output contains `&lt;b&gt;` |
| L5 | No tables, footnotes, task lists, heading IDs. | Out of the v1 subset. | unit: `\| a \| b \|` and `- [ ] x` render as text (the latter as a list item with literal `[ ]`) |
| L6 | Images: `https` only; `http`, `data:`, relative -> literal text. No uploaded/inline (CID) images. | CSP `img-src` blocks `http` in the editor; mixed content; CID is future work. | unit per scheme |
| L7 | Paste and drop insert plain text; pasted Markdown is NOT rendered. | Rendering it needs a JS Markdown parser. | js: `beforeinput` `insertFromPaste` with HTML payload -> `preventDefault` + `insertText(plain)` |
| L8 | No ⌘K (link dialog); ⌘U is blocked. | A dialog needs `prompt()` (banned); Markdown has no underline. | js: ⌘U keydown is `preventDefault`ed; no ⌘K handler |
| L9 | No per-user "Markdown off" switch. | One send path; ordinary prose stays literal (see accidental-formatting tests); JS-off textarea is the fallback. | none (absence) |
| L10 | A non-BFCache reload restores the textarea, but the editor shows server HTML and wins on submit. | Accepted for v1. | none; documented only |

Documentation surfaces for these (step 9): README "Composer" section with a
"Limitations" subsection listing L1-L10 in user terms; memory.md (one line + a
pointer to this table); this plan.

## Implementation notes (2026-09-28)

Steps 0-9 are done. Suite: 1669 passed, 0 failed, 2 skipped (baseline 1264).

Step 8 (the editor) was verified in Chromium only. The Safari/Firefox/iOS/Android
hand pass was **deferred, not run** — it needs real browsers and real keyboards,
which the test harness cannot stand in for. Code is done; the manual check is
outstanding.

Changes against the plan, all made during implementation:
- **Line model:** one source line = one `<div>`, a blank line = `<div><br></div>`
  (not paragraphs + `<br>`). It is what the editor produces, so round trips are
  exact.
- **Numbered list trigger:** only `1. ` converts in the editor. Other numbers are
  prose there, and the serializer escapes them (`3\. `), so editor and server
  agree.
- **Code block trigger:** ```` ``` ```` + space (not Enter). Exiting on an empty
  last line inserts a `<div>` after the `<pre>` with the caret placed outside:
  `formatBlock` nests a `<div>` inside a `<pre>` in Chrome.
- **insertHTML** is used for inline code, links and images, with markup built
  here, text escaped with `$.escapeHtml` and URLs through the allowlist. It is
  never used with pasted or foreign HTML.
- **Reply quotes** are written `> > x` (spaced), the serializer's canonical
  form, so an untouched reply is posted byte for byte.
- **A line longer than 64 KB** is rendered as escaped text without inline rules
  (`MD_INLINE_MAX_LINE`); quotes nest 16 deep at most (`MD_MAX_QUOTE_DEPTH`).
- **Drafts:** a text part is Markdown (Emmmail wrote it); an HTML-only draft is
  from another client and is `md_escape()`d.
- tests/js.php: the DOM stub got `closest()`; the serializer is tested against
  DOMDocument trees of real `md_to_html()` output passed in `MD_FIXTURES_PATH`.

Verified in Chromium (terminal-browser): every trigger; Backspace after a trigger
restores the marker; two `undo` steps restore it too; `---` inside a code block
stays text; exiting quotes and code blocks; a prefilled reply with hostile quoted
content serializes back byte for byte, loads no image and links no label; rich
paste becomes plain text.

## Settled decisions

- No per-user off switch (L9).
- Flat lists only (L1, L2). Nested lists and repeated-marker shortcuts rejected
  for v1.
- Strikethrough `~~x~~` -> `<s>` is in v1 (single `~` stays literal).
- Reply attribution becomes `On <date>, <from> wrote:`; forward keeps an escaped
  header block without `>`.
