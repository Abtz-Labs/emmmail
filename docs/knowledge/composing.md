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
it. That is why the prefix goes through one helper, pinned by counting its two call sites.

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
