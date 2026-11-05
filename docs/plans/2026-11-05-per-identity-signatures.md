---
status: in progress (code, tests and docs done; manual browser pass owed)
---

# A signature for each send-as identity

## Goal

Every identity (the account address and each Send-as alias) has its own signature.
The composer prefills the signature of the identity that is selected. When the user
changes From in the composer, the signature in the body changes too, if the user
has not edited it.

Example: `me@example.com` signs `This is me`, `help@example.com` signs `Help Team`.

## Decisions (from the user)

1. **An alias with no signature has no signature.** There is no fall back to the
   account signature.
2. **Changing From swaps the signature** in the editor when the old signature is
   still there, unchanged (option b).
3. **Settings shows stacked textareas**, one for each identity.

## Design

### Storage: no schema migration

- `settings.signature` stays the signature of the account address. There is no
  change for existing users.
- New setting `send_as_signatures`: a JSON object, `{"<lowercased alias>": "<text>"}`,
  written with `JSON_INVALID_UTF8_SUBSTITUTE`, so one bad byte cannot wipe the map.
  A JSON object in one row is the simplest shape: it is rewritten whole, so it
  leaves no orphan rows.
- Read: `json_decode(db_get_setting($me, 'send_as_signatures', '{}'), true)`. A
  value that is not an array becomes `[]`. Each entry is a string, or it gives `''`.

### One lookup: `signature_prefix(string $address, string $me): string`

- `$address` is the account address (case-insensitive) → `trim(db_get_setting($me, 'signature', ''))`.
- Else → `trim(map[strtolower($address)] ?? '')`. No fall back.
- It reads the DB for `$me`, like `send_as_identities()`.
- Three call sites, pinned by exact strings:
  - `reply_form_data()`: `$from = identity_for_message(...)`, then
    `$sigPrefix = signature_prefix($from, $me);`. `'from' => $from` reuses it.
  - `handle_forward()`: `$sigPrefix = signature_prefix($me, $me);` (the `$me` line
    moves above it). Forward stays from `$me`, as it does today.
  - blank `handle_compose()`: `$sigPrefix = signature_prefix($me, $me);`,
    `'body' => $sigPrefix,`. Today it reads `user_setting('signature')`, and that
    value is not trimmed. The trim is harmless because the save already trims.
- `send_as_identities()` is **not** changed. It runs on every reader open and on
  send, where the signatures are not needed. `render_compose()` adds
  `'signature' => signature_prefix(address, current_user())` to each identity.

### Settings UI (stacked)

- The existing `Signature` textarea stays (`name="signature"`). Its label names the
  account address. The old note ("Appended to the bottom ...") is wrong today,
  because the signature goes on top. It is replaced.
- One block for each saved alias: a hidden `send_as_signature_for[]` holds the
  address, and a `send_as_signatures[]` textarea holds the text. The label is
  `h(identity_option_label($alias))`. Parallel arrays, not `[<address>]` keys:
  PHP cuts a key at the first `]`, and a quoted local part can contain one.
- A note: "Save a new Send-as address first, then add its signature here."
- Save: a pure cleaner, `send_as_signatures_clean(array $aliases, $for, $text): array`.
  It follows the precedent of `folder_map_clean()` and `send_as_parse()`, because
  `handle_settings()` ends in `redirect()` (exit) and cannot be unit-tested.
  - A `$for`/`$text` that is not an array is treated as `[]`, so a forged string is
    harmless.
  - Values that are not strings are skipped. Values are trimmed. Empty values
    are dropped.
  - Only addresses in the parsed `$aliases` are kept (case-insensitive), keyed by
    lowercase.
  - It runs after `send_as_parse()`.

### Composer: swap on From change (option b)

**Server (`view_compose()`, only when the From row is shown).** Each identity gets
one hidden source inside the form, outside `.compose-body`:

```html
<div class="compose-signature hidden" data-signature-for="h(address)" data-signature-md="h(sig)">md_to_html(sig)</div>
```

- An empty signature renders `<div><br></div>`, which is one empty line. That is the
  first line of a reply or forward with no signature (`"\n\n" . quote`).
- The select gets `autocomplete="off"`, so Firefox does not restore a From that
  disagrees with the body.
- The editor HTML does not change.

**JS, inside the existing `.md-editor` block.** That block owns `dirty`, `sync`,
`empty` and `undoable`.

- `prev` = `from.value` at boot (`autocomplete="off"` makes it the server's choice). A source is found by
  looping over `.compose-signature` and comparing `getAttribute('data-signature-for')`
  without case. No value selectors: the stub cuts selectors at `.`, and a real
  browser needs escaping.
- On `change` of `select[name="from"]`:
  1. Get the old source (for `prev`) and the new source (for the selected address).
     Then set `prev` to the new address, also when nothing is swapped.
  2. `blocks(nodes)` = the editor's top-level nodes without layout text. Layout
     text is whitespace only and contains `\n`, the same test as `mdBlocks`'
     `flush()`.
  3. Find the run to replace:
     - The old signature is not empty: search the element blocks for the first
       run whose `mdBlocks` Markdown equals the old source's Markdown. The run
       has as many blocks as the old source. Compare first; then a bare text
       node, or a top-level `BLOCKQUOTE` that is not the signature, stops the
       search, so a reply quote is never searched.
     - The old signature is empty: the editor is empty (no text, no image), so
       the whole content is the run. Or the first real node is a `DIV` whose
       Markdown is `''`. Anything else does not match.
  4. No match: change nothing. The user edited or deleted the signature. Gmail
     does the same.
  5. Match:
     - Insert a `cloneNode(true)` copy of each **element** child of the new
       source before the run. Remove the run with `ed.insertBefore` and
       `ed.removeChild` only.
     - Set `undoable = null`.
     - Keep the textarea byte for byte:
       - If `!dirty`, the run is the top block (or the editor was empty),
         `ta.value` starts with the old `data-signature-md`, and a line break
         or the end follows it, then `ta.value = newMd + rest`, and `dirty`
         stays false.
       - Else `dirty = true; sync();`.
     - Then `empty()`.
- The check has no state, so a draft works as well.

### Known limits (documented)

- The swap needs the rich editor. With no `execCommand` (the textarea fallback)
  or no JS, the signature does not change. The user edits it by hand.
- Ctrl+Z after a swap is not reliable. The swap is a direct DOM change, and the
  browser's undo history does not have it.
- An edited signature is not swapped. With no old signature, text typed into the
  first line stops the insert.
- If the forwarded text below has the same old signature as its own blocks, and
  the top one was edited, that copy is swapped.
- A signature with an unclosed code fence takes the quote into the `<pre>`. This is
  true today too. It is not swapped.
- The hidden sources load the user's own `https` images in signatures.

## Steps (TDD)

1. **Red, unit** (`tests/unit.php`):
   - `signature_prefix()`:
     - The primary reads `signature`.
     - An alias reads the map, without case.
     - An alias that is not in the map gives `''`.
     - Bad JSON, or a JSON value that is a list or a number, gives `''`.
   - `send_as_signatures_clean()`:
     - It keeps only parsed aliases.
     - It trims, and drops empty values.
     - Values that are not strings are skipped, and a `$for`/`$text` that is not an array is treated as `[]`.
     - It matches without case.
   - `reply_form_data()` with `send_as_identities()`:
     - A reply to `help@` opens with `Help Team` and does not contain `This is me`.
     - A reply to an alias with no signature opens with `"\n\nOn "` and does not contain
       `This is me`.
   - Existing guards change:
     - `signature_prefix()` with no arguments (line ~2431) becomes `($sigUser, $sigUser)`.
     - Exact strings: `signature_prefix($me, $me);` ×2, `signature_prefix($from, $me);`,
       `'body'        => $sigPrefix,`, `'body'        => $sigPrefix . $quote,` ×2.
     - `user_setting('signature'` appears once, in the settings render.
   - Composability guard (green from the start; it describes behaviour that
     exists): for plain, multiple lines, `-- \nName`, a list, bold, `# H`,
     `> q`, `---` and `Best,\n\nName`, `md_to_html(sig . "\n\n" . quote)` starts
     with `md_to_html(sig) . "\n"`.
   - `view_compose()`:
     - With aliases, there is one source for each identity. The address keeps its
       case and goes through `h()`.
     - The empty source is `<div><br></div>`, and `data-signature-md` is set.
     - The sources are outside `.md-editor` and have no `name`.
     - The select has `autocomplete="off"`.
     - With no aliases there are no sources, and the editor HTML is the same.
   - `view_settings()`:
     - There is an alias block with the label and the escaped value.
     - With no aliases there is no block, and an old-style `$d` gives no warnings.
     - The note text is present.
   - Source guard: `handle_settings()` calls `send_as_signatures_clean(` after
     `send_as_parse(`.
2. **Red, js** (`tests/js.php`):
   - Stub: add a generic `insertBefore` to `makeEl`. Add a test-local `toEl(doc, tree)` that builds
     `makeEl` nodes from `md_html_tree()` JSON. Its `childNodes` is `children`, and it has
     `cloneNode(deep)` and `nodeName`.
   - The fixtures come from the real `view_compose()` (DOMXPath over `.md-editor` and
     `.compose-signature`) for reply, forward-style and blank bodies. The expected
     bodies come from `reply_form_data()`.
   - Cases:
     - Untouched reply A→B: the editor and the textarea are B's body, byte for byte.
       `dirty` stays false.
     - Text typed above the signature: it is swapped and serialized.
     - The signature is edited: nothing changes, and the node objects are the same.
     - Blank compose, no old signature → `Help Team`. Also the reverse, and the
       empty first line of a reply.
     - A→B→A works.
     - The case of the address differs.
     - The same signature for both.
     - Without `execCommand`, `ta.value` does not change.
3. **Green**: the helper, the cleaner, settings, the sources, the JS. `just test`.
4. Docs:
   - README (the settings list ~90/~398, the send-as section ~162, the `settings`
     table ~437, the limits).
   - `docs/knowledge/composing.md` (signature: three exact call sites; the swap).
   - `docs/memory.md`.
5. Code review (parallel `code-reviewer`). Fix the findings, then `just test` again.
6. A manual browser pass, owed to the user, in Chrome and Firefox:
   - Enter at the start of the signature, then change From.
   - A reply to an alias.
   - A forward.
   - A draft.
   - A blank compose.
   - Both themes.
