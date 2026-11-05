---
status: in progress (code and tests done; manual pass owed)
---

# Send as an alias (multiple sending addresses)

## Goal

Let one signed-in account send and reply from several addresses, without logging
out. The account address stays the default for a new message. A reply selects the
address that actually received the original message, and the user can always
change it in the composer.

## Why registration is manual in v1

There is no provider-neutral way to discover send-as addresses:

- IMAP has no send-as/identity list.
- Gmail and Outlook expose one only through their own OAuth APIs; the mailbox
  password the app already holds is not enough.
- Migadu exposes mailbox *identities* through `api.migadu.com`, but the API needs
  a separate API key from Migadu Admin → My Account → API Keys, not the mailbox
  password.

A common adapter would be several providers, several credential types, and new
network calls in a zero-dependency single-file app. Manual registration works on
every provider and is the v1. A provider adapter is a later candidate, behind the
same identity model.

## Decision summary

- **One new setting, no schema migration.** `settings.send_as` holds the alias
  list as text: one address per line, optionally `Name <address>`.
- **The account address is always an identity.** It is first in the list, cannot
  be removed, and is the default for a new message.
- **A From row appears in the composer only when aliases exist.** It is a real
  `<select name="from">`, so it works with JavaScript off.
- **A reply pre-selects the alias found in the original To, then Cc.** If none is
  found, the account address is used. The user can still change it.
- **Reply-all never addresses your own aliases.** Every configured identity is
  excluded from both groups, exactly as the account address already is.
- **The chosen address is the `From:` header and the SMTP envelope sender**
  (`MAIL FROM`). The authenticated SMTP user stays the account.
- **An alias with no name sends as `From: <alias>`**, not under the account's
  display name. The primary uses the existing display name.
- **Drafts and undo-send keep the chosen address.**
- **The server never trusts the posted `from`.** It resolves it against the
  stored list and falls back to the account address.

## Prerequisite fixes found in review

These are small, but the feature depends on them. Each gets its own red test.

### 1. A To with several addresses is rejected today

`handle_send()` validates the whole To string with `FILTER_VALIDATE_EMAIL` and
passes it to `smtp_send()` as one recipient (`index.php:5606-5619`, `5641`). A
comma list therefore fails with "Invalid email address", and `reply_all_recipients()`
returns exactly such a list. Reply-all to more than one person does not work.

Add three pure helpers next to `header_address_list()`:

- `header_address_entries(string $header): array` — the existing quote-aware split
  (keep the current parsing), returning `['text' => ..., 'address' => valid-or-'']`.
- `header_address_list()` — becomes a projection over the entries, preserving its
  current output exactly.
- `compose_recipients(string $to, string $cc, string $bcc): array` — returns
  `['ok' => bool, 'error' => string, 'recipients' => []]`. It rejects any entry
  whose address is invalid (no silent drop) and returns the valid addresses as
  individual strings.

`handle_send()` uses `compose_recipients()` for validation and for the SMTP
`RCPT TO` list. The `To:`/`Cc:` headers still use the text as typed, so display
names survive. This fixes multi-recipient To, multi-recipient Cc/Bcc, and
reply-all in one change.

### 2. A display name with a comma breaks the From header

`smtp_build_raw_message()` writes `From: $fromName <$from>` with no phrase
quoting (`index.php:1344-1354`). `Sales, EMEA <sales@x>` is malformed. The new
per-alias names make this easy to hit. Add:

- `format_address_header(string $name, string $address): string` — empty name
  gives `<address>`; non-ASCII keeps the existing B-encoding; an ASCII name with
  RFC 5322 specials (`,;:<>@\"[]().`) is a quoted string with `\` and `"`
  escaped; a simple name is unchanged.
- Use it for `From:` only. Existing `From: Display Name <...>` output stays
  byte-identical.

### 3. An undo-send failure is silent

`handle_flush_undo()` removes the queue entry **before** `smtp_send()`
(`index.php:5733-5751`), and the countdown's `fetch('?page=flushUndo')` ignores
the response (`index.php:8330-8335`). An alias the provider refuses would vanish
without an error, a Sent copy, or a retry.

- Remove the entry only after a successful send.
- On failure return `ok:false` and the SMTP error, and keep the entry.
- The flush JS reads the JSON and shows `win.toast(error, 'error')` (fallback
  `alert`), instead of discarding the response.
- v1 does not reopen the failed message in the composer; that is a later
  candidate. Say so in the README limitation.

### 4. `smtp_send()` does not validate its sender

It validates every recipient but not `$from` (`index.php:4692-4694`). Add the
same `filter_var(..., FILTER_VALIDATE_EMAIL)` gate and a clear
`Invalid sender address.` error. The resolver is still the primary gate.

## Identity model

### Stored form

`settings.send_as`, per signed-in email:

```
support@example.com
Sales <sales@example.com>
```

`send_as_parse(string $raw, string $me): array` (pure):

- Split on CR/LF; trim each line.
- Accept `address` or `Name <address>` (space optional before `<`).
- Strip surrounding quotes from the name.
- Validate the address with `FILTER_VALIDATE_EMAIL`; drop invalid lines.
- Deduplicate case-insensitively; the first spelling wins.
- Drop any line equal to `$me`, case-insensitively (the primary is always
  available).
- Remove `<` and `>` from a name so it cannot break the stored format.
- Cap at 20 aliases and 254 characters per address.
- Preserve the typed order.

`send_as_format(array $aliases): string` is the inverse, so the settings textarea
always shows the normalized list.

`send_as_identities(string $me): array` reads `db_get_setting($me, ...)` directly
(never `user_setting()`, which reads the session user) and returns the primary
first, then the aliases:

```php
[
  ['address' => $me, 'name' => <display_name setting>],
  ['address' => 'sales@example.com', 'name' => 'Sales'],
]
```

An alias with no name keeps `name => ''`. A missing or malformed `send_as`
means "primary only".

### Resolution helpers (pure)

- `identity_resolve(string $requested, array $identities, string $me): array`
  matches whole addresses case-insensitively and returns the matching identity;
  an empty or unknown value returns the primary.
- `identity_for_message(array $email, array $identities, string $me): string`
  iterates **header addresses**, not the identity list: `header_address_list()`
  on `$email['to']`, then `$email['cc']`, skipping `$me`, case-insensitively.
  Returns the first alias found; otherwise `$me`. This makes `To: me, Cc: alias`
  choose the alias.
- `identity_option_label(array $identity): string` renders `Name <address>` or
  the bare address for the composer and tests.
- `own_identity_addresses(array $identities): array` returns every address,
  lowercased. One helper, used by both `reply_form_data()` and the render paths.

## Settings UI

`handle_settings()`:

- On POST, `$aliases = send_as_parse($_POST['send_as'] ?? '', $me)`, then store
  `send_as_format($aliases)`.
- Pass `'send_as' => (string)user_setting('send_as', '')` and
  `'account_email' => $me` to the view.

`view_settings()` adds a form group after **Display name**:

- A `<textarea id="send-as" name="send_as" rows="4">`.
- Note: one address per line; `Name <address>` sets that address's display name;
  the account address is always available; the mail provider must allow sending
  from the address.
- The primary address is shown in the note so the user does not add it twice.
- `send_as` and `account_email` are `h()`-escaped, like every other field in the
  form. Existing fixtures omit them, so read them with `?? ''`.

No JavaScript is required. The note is the only place the provider requirement
is stated before a send fails.

## Compose integration

### Handlers

- `handle_compose()`: primary `from`, full `identities`.
- `handle_edit_draft()`: full `identities`; `from` resolved from
  `$email['from_address']`. An old draft, or one whose alias was removed, falls
  back to the primary.
- `render_reply_form()`: gets `$identities = send_as_identities($me)`, passes
  them to `reply_form_data()`, and adds `identities` to the view data.
- `handle_forward()`: primary `from`, full `identities` (forwarding alias-delivered
  mail as the account is deliberate in v1).
- `reply_form_data()` gains a fourth parameter `array $identities = []` and
  returns `'from' => identity_for_message($email, $identities, $me)`. The default
  keeps existing callers and tests valid.

### `view_compose()`

When `count($identities) > 1`, render the row **above To**:

```html
<div class="compose-row compose-from">
  <label for="compose-from">From</label>
  <select id="compose-from" name="from">
    <option value="me@example.com" selected>Me &lt;me@example.com&gt;</option>
    <option value="sales@example.com">Sales &lt;sales@example.com&gt;</option>
  </select>
</div>
```

- `$identities = $d['identities'] ?? []`; `$from = $d['from'] ?? ''`.
- Resolve `$from` with `identity_resolve()` before rendering, using the first
  identity as the fallback. A removed or unknown draft alias selects the primary,
  never nothing.
- Every value and label is `h()`-escaped.
- With no aliases the form is unchanged: no row, no `from` field, and the server
  falls back to the account address.
- The blank-compose autofocus stays on To; the select is not autofocused. The
  existing autofocus test still applies.
- Add `.compose-row select` matching the input rule, plus `min-width:0` and
  `:focus-visible` (or `:focus`) with the existing `--ring`/`--accent` focus
  treatment. The phone breakpoint already adjusts `.compose-row`.
- No new JS: `kbEditable()` already treats `<select>` as editable, and the
  submit and Markdown handlers hang off `.compose-form`, `.compose-body textarea`
  and `[data-autocomplete]`.

## Send path

`handle_send()` and `handle_save_draft()`:

```php
$identities = send_as_identities($me);
$requested  = is_string($_POST['from'] ?? null) ? $_POST['from'] : '';
$identity   = identity_resolve($requested, $identities, $me);
$from       = $identity['address'];
$fromName   = $identity['name'];   // primary carries display_name; alias is '' or its own name
```

- `smtp_build_raw_message($from, ...)` builds `From:` with
  `format_address_header()` and derives the `Message-ID` domain from the chosen
  address.
- `smtp_send($smtp, $from, $recipients, $raw, ...)` uses it as `MAIL FROM`.
- `smtp_connect()` still authenticates as `$me`.
- The Sent copy is the same raw message, so its `From:` is the alias.
- `handle_send()` stores `'from' => $from` in the undo queue;
  `handle_flush_undo()` sends with that exact stored value. Do not re-resolve it
  against the current settings: the raw header and the envelope must stay in
  step.
- If the provider refuses `MAIL FROM:<alias>`, the send fails with the SMTP
  error. There is no silent fallback to the account address. The composed fields
  are not restored (pre-existing behavior for any failed send); the README says
  so.
- Recipients are deduplicated case-insensitively, keeping the first spelling and
  header order.
- The Sent append is checked. If the message goes out but cannot be filed in
  Sent, `handle_send()` flashes and `handle_flush_undo()` returns a `warning`.
  The message is never sent again.

## Reply and reply-all

- `reply_all_recipients(array $email, string $me, array $own = [])` seeds its
  seen-list with `$me` plus every lowercased `own` address.
- `reply_default_is_all(array $email, string $me, array $own = [])` passes `own`
  through, so the Reply-all button does not lead merely because an alias is in
  the original To/Cc.
- `reply_form_data()` builds `$own = own_identity_addresses($identities)`.
- `view_message_actions()` gains `array $own = []` **after** `$withBack`, so the
  existing `..., false` callers keep working, and passes it to
  `reply_default_is_all()`.
- `view_read()` reads `$opts['own_addresses']`; `render_read()` fills it once.
- `view_thread()` reads `$opts['own_addresses']`; `render_thread()` fills it
  once and passes it to every message's action bar.
- Existing callers pass no `own` list, so old behavior is unchanged.

## Security and correctness

- **Posted `from` is a hint, never a header.** Only the account or an address in
  `send_as` can be used.
- **Names are stored server-side**, not posted with the message. They are
  sanitized by `send_as_parse()` and the From header formatter.
- **No CRLF injection:** addresses pass `FILTER_VALIDATE_EMAIL`; the existing
  header sanitizer still runs; `smtp_send()` validates the sender too.
- **The SMTP password is untouched.** Sending as an alias uses the account's
  authenticated SMTP session.
- **Per-login storage.** Settings are keyed by the signed-in email, like every
  other setting. Logging in with an alias and with the primary address are two
  separate settings sets.
- **Provider responsibility.** The provider must permit the address. On Migadu
  the send-as address is an **Identity** with `may_send` (often created with
  `password_use: mailbox`), not a receive-only **Alias**. The settings note,
  README and `docs/knowledge/environment.md` must say so.
- **Detection is header-based.** To/Cc only. A Bcc-only delivery, or a message
  delivered through a mailing list, cannot be detected; the user changes the
  From manually.

## Tests (red first)

`tests/unit.php`:

**Prerequisites**

- `header_address_entries()`: quoted comma names, `Name <addr>`, invalid entries,
  empty input.
- `header_address_list()`: unchanged output on the existing cases.
- `compose_recipients()`: one, several, `Name <addr>`, quoted comma, an invalid
  entry in To/Cc/Bcc, empty Cc/Bcc, no silent drop.
- `format_address_header()`: simple name unchanged, comma name quoted, `"` and
  `\` escaped, non-ASCII still B-encoded, empty name is `<addr>`.
- `smtp_build_raw_message()`: the quoted From name reaches the header.
- Source guard: `handle_send()` uses `compose_recipients()` for both validation
  and `smtp_send()`.
- Source guard: `handle_flush_undo()` stores/uses the queued `from` and unsets
  after success; the flush JS reads `res.ok`.

**Identities**

- `send_as_parse()`: bare, named, `Name<addr>`, CRLF, blanks, invalid,
  duplicates (first wins), primary exclusion (case-insensitive), angle-bracket
  cleanup, order, the 20-alias cap.
- `send_as_format()`: round-trips `send_as_parse()`.
- `send_as_identities()` (DB): primary first with the display name; no fallback
  name on an unnamed alias; missing setting yields primary only; malformed
  stored text yields primary only.
- `identity_resolve()`: exact, case-insensitive, unknown, empty.
- `identity_for_message()`: alias in To; alias only in Cc; primary plus alias in
  To/Cc (alias wins); two aliases (header order, not list order); mixed case; no
  match; no identities.
- `own_identity_addresses()`: lowercased, includes the primary.
- `reply_all_recipients()`: a capitalised configured alias is dropped from To and
  Cc.
- `reply_default_is_all()`: an alias in To does not make Reply-all lead.
- `reply_form_data()`: `from` is the matched alias; primary when nothing matches;
  existing fields are untouched.
- `identity_option_label()`: named and bare.

**Views and wiring**

- `view_compose()`: no aliases means no From row; two identities render a
  `<select name="from">` with both options, the requested one selected, a removed
  `from` selects the primary, labels and values escaped.
- `view_settings()`: `name="send_as"`, the stored text escaped, the note, the
  account address.
- `view_message_actions()`: the `$own` argument is after `$withBack`; a capitalised
  alias does not make Reply-all lead.
- Source guards for the wiring: `handle_compose()`, `handle_forward()`,
  `handle_edit_draft()` and `render_reply_form()` pass `identities`;
  `handle_edit_draft()` resolves `$email['from_address']`; `handle_settings()`
  calls `send_as_parse()` and `send_as_format()`; `render_read()` and
  `render_thread()` fill `own_addresses`; `view_read()` and `view_thread()` pass
  it.
- Exact call snippets: `smtp_build_raw_message($from,`,
  `smtp_send($smtp, $from,`, `'from' => $from`. No `$_POST['from']` may reach the
  header or envelope without `identity_resolve()`; count the direct occurrences
  rather than relying on an impossible "never" check.
- CSS guard: `.compose-row select` and its focus rule exist.
- The draft round-trip cannot be tested end to end: `_imap_stub.php` has no
  APPEND. Cover it with the source guard on `$email['from_address']` and a real
  smoke check, and say so.

No `tests/js.php` change is required for the From row. If the flush change is
expressible with the existing fetch stub, add a JS case; otherwise pin it with the
source guard above.

## Docs

- `README.md`:
  - Features list: multiple send-as addresses and reply auto-selection.
  - Per-account settings: the `send_as` rows and format.
  - A short "Aliases and send-as addresses" section: manual registration, the
    provider must allow it, Migadu Identity vs Alias, the To/Cc detection (Bcc and
    list deliveries cannot be detected), and the lack of discovery.
  - Limitations: a failed send does not restore the composer; undo-send reports a
    refused alias but does not reopen the message.
  - Database: no new table; the list lives in `settings`.
- `docs/knowledge/composing.md`: a "Sending identity" subsection with the
  invariant that the posted `from` is resolved, the envelope uses the chosen
  address, reply-all excludes every own address, and drafts/undo keep it.
- `docs/knowledge/security.md`: the server-side `from` gate and case-insensitive
  own-address matching.
- `docs/knowledge/css-ui.md`: the `.compose-row select` convention, focus ring and
  `min-width:0`.
- `docs/knowledge/environment.md`: Migadu Identity vs Alias, and why API
  discovery needs an admin API key.
- `docs/memory.md`: one plan-status line. The file is at its 150-line budget, so
  consolidate an older finished entry at that time rather than exceed it.

## Out of scope (v1)

- Provider API discovery (Gmail/Outlook OAuth, Migadu API key).
- Heuristic suggestions from received mail.
- Per-alias signature, reply-to, or SMTP credentials.
- Sending through a different SMTP account per alias.
- A configurable default identity for new messages.
- Detecting Bcc-only or mailing-list deliveries (`Delivered-To`/`X-Original-To`).
- Automatic fallback when the provider rejects the alias envelope.
- Restoring a failed send to the composer.

## Later candidates

- A provider adapter seam: `send_as_discover($me, $config)` returning addresses,
  with Migadu first (API key), then Gmail/Outlook if the app ever gains OAuth.
- Suggestions from cached recipients on the account's own domains.
- A "reply as the same alias" rule for messages opened in Sent.
- Reopening a failed queued/undone message in the composer.

## Implementation order

1. Prerequisite fixes and their tests (recipient parsing, From quoting, undo
   visibility, SMTP sender validation).
2. Identity helpers and settings storage/tests.
3. Settings UI and compose UI.
4. Send, reply, reply-all, drafts, undo wiring.
5. Docs, memory, and the manual verification.

## Verification

- `php tests/run.php` green, then `just test`.
- Manual, with a real provider that allows aliases:
  1. Add two aliases in Settings; save; reload and confirm normalization.
  2. Compose as the primary and as an alias; check the received `From:` and the
     Sent copy.
  3. Send a message to an alias, reply, and confirm the composer pre-selects it.
  4. Reply-all to a message with several To recipients; confirm every recipient
     is addressed once and no own alias appears in To or Cc.
  5. Save a draft as an alias, reopen it, and confirm the From survives.
  6. Queue an undo-send as an alias and confirm the flushed message uses it; make
     the provider reject it and confirm the error is shown, nothing is filed in
     Sent, and the message is not silently lost.
  7. Try an address the SMTP server refuses; confirm the error is visible and
     nothing is filed in Sent.
  8. With JavaScript off, confirm the From select submits and the reply arrives
     from the alias.
  9. At phone width, confirm the From row does not overflow and the focus ring is
     visible in both themes.
