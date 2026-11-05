# Composing, sending and drafts

## Markdown composer
**Markdown goes over the wire.** `textarea[name=body]` stays the form field. `.md-editor`
(contenteditable, shipped `hidden`) is shown by JS only if `doc.execCommand` exists, and writes
`mdSerialize()` back **only after a real edit** (dirty flag) — so an untouched draft or reply
posts byte for byte.

PHP: `md_to_html()` (editor + base), `md_email_html()` (full doc + inline styles, no colours),
`md_escape()` (text read literally), `quote_source_text()`, `forward_quote()`.
`smtp_build_raw_message(..., textBody:)` puts the source in the text part, CRLF, no
`strip_tags`.

**The editor is in the main DOM and the CSP is not a script barrier**, so `md_to_html()` is a
one-pass tokenizer: text is `h()`d on output, attributes are built once, and the URL allowlist
runs on the raw text. Never make it a regex cascade.

**Quoted, forwarded and HTML-only-draft text is untrusted** — it goes through `md_escape()`, or
a quoted `![](tracker)` loads on Reply.

JS `mdEscape` / `mdAutolink` / `mdUrlOk` mirror the PHP ones, and `tests/js.php` checks them
against DOMDocument trees of real `md_to_html()` output (`MD_FIXTURES_PATH`).

The help row is `.md-help-row` (after the attachments, above the actions, with a top border) and
holds a button that opens `#md-help-dialog`, whose table `view_md_help_dialog()` renders after
the form. The row ships `hidden` and JS shows it only if `showModal` exists. **Its table
duplicates the README tables: change both.** The title has `autofocus`.

## v1 limitations L1-L10
These are decisions, pinned by the `markdown v1 limitations` tests and listed in the README.
Flat lists only; `-- x` / `** x` stay literal; Tab is not intercepted; no raw HTML; no tables,
footnotes, task lists or heading IDs; images are https-only; paste and drop insert plain text;
no ⌘K link dialog; no per-user Markdown switch; a non-BFCache reload lets the editor win on
submit.

**Lifting one is test + README + plan, together.**

## Threading
**Threading needs both ends wired.** `handle_send` reads `in_reply_to_id`, so the composer must
render it. `References` = parent chain + parent Message-ID. `Reply-To` beats `From` for reply
and reply-all.

## Outbound headers
**The sender sets only headers it owns.** `smtp_build_raw_message()` must never emit
`Delivered-To:` or `Return-Path:`. Postfix's delivery agents read `Delivered-To:` to break
forwarding loops (`delivered_hdr_find()`), so a message that already names the recipient there is
rejected with `5.4.6 mail forwarding loop for <rcpt>` — netcup did this to a reply addressed to
`mail@sysscore.com` on 2026-11-05. `Return-Path:` is inserted by the final delivery MTA
(RFC 5321 4.4). Identity travels in the SMTP envelope (`MAIL FROM`) and `From:`.

## Sending identity (Send as)

A reply must go out as the address that received the message. `reply_form_data()` asks
`identity_for_message()`: it scans the original To, then Cc, through `header_address_list()`,
and returns the first configured alias. No match means the account address.

**The posted `from` is a hint.** `handle_send()` and `handle_save_draft()` run it through
`identity_resolve()` against `send_as_identities()`, then use the result for both `From:` and
`MAIL FROM`. Never pass `$_POST['from']` to the builder or `smtp_send()` directly.

**Own aliases are the user.** `reply_all_recipients(..., $own)` seeds its seen-list with every
identity, so reply-all never mails an alias. The reader passes `own_addresses` to
`view_message_actions()`; without it the Reply-all button leads on the user's own alias.

**Drafts and undo keep the From.** The raw draft carries it, so `handle_edit_draft()` reads
`$email['from_address']`; the undo queue stores `from` and `handle_flush_undo()` uses it. The
queue entry is removed only after a successful send.

**A From name with a comma is quoted.** `format_address_header()` quotes RFC 5322 specials and
falls back to B-encoding for non-ASCII. `smtp_build_raw_message()` is its only caller.

**Recipient parsing is separate from the header.** `compose_recipients()` turns To/Cc/Bcc into
individual `RCPT TO` addresses; the headers keep the text as typed. A multi-address To was
rejected as one address before this, so reply-all to a group did not work.

## The conversation view

`thread_root()` (`index.php`) is the whole identity: first `References` token, else
`In-Reply-To`, else the message's own `Message-ID`, else `''`. An empty result becomes the
sentinel `uid:<folder>:<uid>`, which is unique per message, so id-less mail never merges.
The key is stored in `messages.thread_key` (schema v4) and a reply filed in Sent joins its
Inbox thread because **it carries the same root**, not because of any cross-folder lookup.

Two invariants that are easy to break:

- **A folder is threaded only when `serverTotal <= cachedRows`.** A partial window would
  let a conversation straddle a page boundary, and paginating conversations over it would
  replay cached messages against the flat live tail (two page schemas cannot share `?p=`).
  A bigger folder lists flat, exactly as before.
- **`sync_folder()` computes the key, not `overviewRange()`.** `overviewRange()` exports
  the three ids; the folder is only known in `sync_folder()`, which is where the sentinel
  is built. Adding the ids to `fetchOverviews()` alone (the live path) does nothing for the
  cache.
- **A folder with any keyless row rebuilds itself once.** Grouping `thread_key=''` puts
  every row in one conversation, and a normal sync only fetches `UID > max`, so it can
  never repair existing rows. `db_count_unthreaded()` feeds `sync_decide()`, which returns
  `purge_all` with reason `unthreaded`; `folder_view_data()` also checks it, so the rebuild
  happens on the render that would show the bug rather than a poll later. Steady state is
  zero, so this never loops. (A cache written between the v4 migration and the extractor is
  exactly how the one-thread bug shipped.)

`db_list_threads()` anchors display on the conversation's **global newest** member (so a
Sent reply surfaces) and the row's `data-uid` on the folder newest (for the cursor).
`db_thread_members()` excludes Drafts/Trash/Junk. A conversation row's read toggle posts
`thread=<key>` to `?page=markRead`; the box picks every in-folder member.

## Drafts
**IMAP has no update.** Saving an existing draft appends and then deletes the old copy, so
`compose_gather_attachments()` must read the old copy **before** that delete. `formnovalidate`
on Save-draft is load-bearing.

## The signature
The signature appends at view/prefill level (`reply_form_data`, `handle_forward`, blank
`handle_compose`) and stays visible and editable in the textarea before send.

`signature_prefix()` returns **just the signature**; the quote and forward builders own the
single separating blank line (and the "room on top" when there is no signature). It used to end
in `"\n\n"` *and* so did the quote builder, so the composer showed three blank lines.

In a merge, both quote sites lost the signature: the stash owned `$sigPrefix` and the pull owned
the `forward_quote()` / `reply_form_data()` bodies, so taking either side whole silently drops
it. That is why the prefix goes through one helper, pinned by its three exact call strings:
`signature_prefix($from, $me)` (reply) and `signature_prefix($me, $me)` (forward, blank compose).

**One signature per identity.** `signature` is the account's; `send_as_signatures` is a JSON
map keyed by lowercase alias. An alias with no entry has **no** signature (no fall back). The
settings form posts parallel `send_as_signature_for[]` / `send_as_signatures[]` lists, not
`[<address>]` keys (PHP cuts a key at `]`), through the pure `send_as_signatures_clean()`.
The page must render a box for every saved alias: a save without them wipes the map.

**The From swap.** `view_compose()` ships one hidden `.compose-signature` per identity
(`data-signature-for`, `data-signature-md`, the rendered blocks; empty = `<div><br></div>`, the
empty top line of a reply). On `change` the JS finds the old signature by its blocks'
Markdown and replaces them; an edited one is not found and stays. It works because
`md_to_html(sig . "\n\n" . rest)` starts with `md_to_html(sig)` (unit guard). The textarea is
patched byte for byte only when the editor is untouched **and** the swapped blocks are the top
lines the source starts with; otherwise it is serialized. Without that position check a draft
could post a body that differs from the editor.

## Undo send
A session queue, `$_SESSION['undo_queue']`. `handle_send` stores when the period is > 0, a JS
countdown bar shows an Undo button, `handle_flush_undo` sends after expiry, and
`handle_undo_send` cancels. All AJAX.

**In a submit handler, round-trip feedback is unconditional; only the AJAX interception may be
conditional.** The `if (undoSeconds <= 0) return;` sat *before* `markBusy(btn)`, so in the
DEFAULT configuration (undo off) Send never spun and the nav bar never ran.

## POST overflow
Past `post_max_size` PHP empties `$_POST` and `$_FILES`. `detect_post_overflow()` and
`parse_php_size()` run **before** `csrf_verify()` in both `handle_save_draft` and
`handle_send`, so the user sees "Message too large" instead of "Security token invalid." The JS
`postMaxBytes` variable is injected from PHP and warns before the upload.
