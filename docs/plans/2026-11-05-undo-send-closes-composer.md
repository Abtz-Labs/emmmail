---
status: in progress (code, tests and docs done; manual browser pass owed)
---

# Undo send: close the composer, show a bar on every page

## Problem

With undo send on, Send posts by AJAX and the composer stays open. When the
countdown ends the bar goes away, but the filled composer is still there, so it
is easy to send the message a second time. With undo off, the server sends and
redirects to the Sent folder.

## Decisions (from the user)

1. **Send closes the composer and goes to Sent,** in both modes.
2. **The countdown bar shows on every signed-in page** until the message is sent
   or cancelled.
3. **Undo saves the message to Drafts and opens that draft.** This keeps the
   attachments, From, Cc/Bcc and threading.
4. **If that Drafts save fails, the send is still cancelled,** and an error says
   so.
5. **A late entry is sent at the next page load.** The tab could be closed during
   the countdown. Today it is dropped silently.
6. **A failed flush (any failure) moves the message to Drafts** and shows the
   error. If the Drafts save fails too, the entry stays and is tried again. After
   3 failed tries it is dropped, with an error that says so.
7. **A composer sends once (server-side `compose_id`).** Back can restore a sent
   composer filled in (bfcache, or a reload with form restore). A second Send
   from it is refused and goes to Sent. This replaced the first idea, a
   `pageshow` redirect to the inbox. That redirect also blocked Back after a
   *failed* send, which is the only way to get the text back.
8. **The undo branch queues before `smtp_connect()`.** Today it opens an SMTP
   connection that it does not use and does not close.

## Design

### Server

- **`imap_for_session(bool $redirectOnFail = true)`.** With `false`, a failed
  connection returns `null` and does not redirect to logout. The JSON handlers
  below use `false`. A logout inside a `fetch` destroys the session, and every
  other queued message with it.
- **`handle_send()`:**
  - The undo branch moves above `smtp_connect()`. It no longer has a JSON reply
    (the AJAX submit was its only caller) or a "queued" flash.
  - It redirects to the Sent folder, the same as the sent path:
    `?page=folder&folder=<Sent or INBOX>`.
- **`undo_save_draft(?MailImap $imap, array $entry): ?string`.** It has two
  callers: undo and a failed flush.
  1. With no IMAP or no Drafts folder, it returns `null`.
  2. It reads `statusFor($drafts)['uidnext']`.
  3. It appends `smtp_raw_with_bcc(raw, bcc)` with `\Draft \Seen`. A failed
     append returns `null`.
  4. Only after a good append, it deletes the old `draft_uid` copy, like
     `handle_save_draft()`, and invalidates the Drafts cache.
  5. It finds the new UID: `selectFolder($drafts)`, then `uidSet('UNDELETED')`,
     then keeps the UIDs `>= uidnext`. The c-client search has no `HEADER`, so
     it cannot search by Message-ID.
  6. If exactly one UID is left, it returns `?page=compose&draft=<uid>`.
     Otherwise it returns `?page=folder&folder=<urlencode(Drafts)>`.
- **`handle_undo_send()`:**
  - A missing token gives `{ok:false, error:'This message is no longer queued.'}`.
  - Otherwise it unsets the entry, then calls
    `undo_save_draft(imap_for_session(false), $entry)`.
  - It returns `{ok:true, location}`. If the save failed, it returns
    `{ok:true, location:null, error:'Send cancelled, but the message could not be saved to Drafts.'}`.
- **`handle_flush_undo()`:**
  - No "expired" drop.
  - A missing token gives `{ok:true, gone:true}`.
  - The order is SMTP send, then unset, then the Sent copy. The IMAP step uses
    `imap_for_session(false)` and is skipped when that returns `null`. The mail
    is already away at that point.
  - A failure (connect or send) calls `undo_save_draft()`:
    - Saved: unset, then `{ok:false, error:'<smtp error>. The message is in Drafts.'}`.
    - Not saved: `tries++`, but only a minute after the last counted try, so a
      burst of page loads while offline counts as one try. At 3 tries: unset, then
      `{ok:false, error:'<smtp error>. The message could not be sent or saved, and was dropped.'}`.
      Below 3: `{ok:false, error:'<smtp error>. It will be tried again.'}`.
  - The session lock of the `files` handler serializes flush and undo across
    tabs. There is no `session_write_close()` in either handler (guarded).
- **`view_undo_bars(array $queue, int $now): string`** (pure). `render_page()`
  echoes it for signed-in pages, next to `.toast-container` and before
  `<main>`. It must stay out of `.mailbox`, which `loadMailbox()` replaces.
  - Markup: one `.undo-stack`, and in it one `.undo-bar role="status"` for each
    entry, with `data-undo-token` and
    `data-undo-left = max(0, expires - now)`.
  - Text: `Sending message.`, then `<span class="undo-bar-countdown" aria-hidden="true">Ns</span>`,
    then `<button type="button" class="btn btn-sm undo-bar-btn">Undo</button>`.
  - An empty queue gives `''`.
- **Compose:** the hidden `undo_period_seconds` input, and its data keys in
  `handle_compose`, `handle_edit_draft`, `handle_reply` and `handle_forward`, are
  removed. The settings page keeps its own key.

### JS

- **Compose submit:** the double-submit guard and `markBusy()` stay. The AJAX
  block goes, and the browser posts the form.
- **`pageshow`:** if `ev.persisted` and the page has an `.undo-bar`, reload. A
  cached countdown may be over, and the server has the true queue.
- **`$('.undo-bar').each`:**
  - The countdown uses a deadline, `end = Date.now() + left*1000`. A chained
    `win.setTimeout` shows `Math.ceil((end - now)/1000)`. This does not drift in
    throttled tabs, and the fake clock can drive it.
  - At 0, the text becomes `Sending…`, the button is removed, and the bar does
    `$.post('?page=flushUndo', {token})`.
    - `ok` (and not `gone`): remove the bar, then `toast('Message sent.', 'success')`.
    - `gone`: remove the bar.
    - Otherwise: remove the bar, then `toast(error)`.
    - A reply that is not JSON: remove the bar, then `toast('Send failed.')`.
  - Undo: stop the timer, `markBusy(btn)`, `$.post('?page=undoSend', {token})`.
    - `location`: `win.location.href = location`.
    - Otherwise: remove the bar, then `toast(error)`. A save failure is a
      `success`-looking cancel, so the type is `error` only when no `location`
      came back.
- No `beforeunload` prompt. The queue survives navigation on purpose.

### CSS

- `.undo-stack`: fixed, bottom 20px, centred, `width:min(360px, calc(100vw - 32px))`,
  a column with a gap, z-index 55 (above the nav backdrop, below the drawer).
- `.undo-bar`: `var(--surface)` and `var(--shadow-md)`, like `.toast`.
  `--card-bg` does not exist, so the bar has been transparent.

## Known limits

- The queue is in the session. A logout or a session expiry drops it. The bar
  says so: "Logging out cancels it. Closing the window sends it on your next
  visit."
- A late entry can go out hours later, at the next visit.
- During a flush (SMTP), navigation in any tab waits for the session lock.
- Without JS the bar shows, but nothing flushes until a page with JS loads.
- Undo opens the draft, and that overwrites `forward_src`. A forward composer
  open in another tab then attaches the draft's parts. This risk is older than
  this change.
- A DATA reply that times out can mean the mail was delivered anyway. It is
  then also saved to Drafts as not sent. A crash between the send and the unset
  sends it again at the next load: a double send, never a loss.
- `undo_save_draft()` has no stub test, because the IMAP stub has no APPEND. The
  browser pass covers it.
- On a phone, the bar can cover the composer's sticky Send row while a second
  message is queued.

## Steps (TDD)

1. **Red, unit:**
   - `view_undo_bars()`:
     - It renders one bar for each entry.
     - The token is escaped, and `left` is clamped to 0.
     - There is `role="status"`, and the countdown is `aria-hidden`.
     - `''` when the queue is empty.
   - Source guards:
     - `render_page()` echoes `view_undo_bars(` before `<main`.
     - `handle_send()` has `$undoPeriod > 0` before `smtp_connect(`, no undo `json_out`,
       and a Sent redirect.
     - `handle_flush_undo()` has no `'expired'` and no `session_write_close`.
       It calls `imap_for_session(false)` and `undo_save_draft(`. Its unset on
       failure sits behind the save or the try cap.
     - `handle_undo_send()` unsets before `undo_save_draft(`, and uses
       `imap_for_session(false)`.
     - `undo_save_draft()`: `statusFor(` is before `appendToFolder(`, and that is
       before `deleteEmailByUid(`.
     - `.undo-bar` CSS has no `--card-bg`.
   - `view_compose()` has no `undo_period_seconds` input (the old group is
     inverted).
   - The old guard "entry survives a failed send" is rewritten.
2. **Red, js:**
   - Compose submit is not `preventDefault`ed with undo on. Send spins.
   - `pageshow`, persisted, on a sent composer: replace to the inbox. A composer
     that was not sent stays.
   - Bar:
     - The fake clock ticks the countdown.
     - At 0: `Sending…`, no button, and a `flushUndo` POST with the token and
       csrf.
     - `ok`: the bar is removed, with a success toast.
     - `gone`: no toast.
     - Error: an error toast.
     - `left=0` flushes at once.
   - Undo: an `undoSend` POST, and `location` is followed. With no location,
     an error toast. After 0 there is no Undo POST.
3. **Green.** Then `just test`.
4. **Docs:**
   - README:
     - The undo section.
     - Rewrite limit "5. If undo send is on and the flush fails…", which this
       change lifts.
     - Fix the duplicate "5."
   - `docs/knowledge/composing.md` "Undo send". "All AJAX" is no longer true.
     Add the session-lock line.
   - `docs/memory.md`.
5. **Review:** parallel `code-reviewer`, then fix the findings and run `just test`.
6. **Manual pass (owed to the user):**
   - Send with undo on, then move between pages during the countdown.
   - Undo a reply that has an attachment: it opens the draft with From, Cc and
     threading.
   - Undo with no Drafts folder.
   - Close the tab during the countdown and open it again.
   - Back after Send.
   - Send with undo off.
   - Both themes, and phone width.
