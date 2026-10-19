# CSS and UI conventions

## Dark tokens live in three places
1. `:root` (light)
2. `[data-theme="dark"]`
3. a **verbatim clone** inside
   `@media (prefers-color-scheme: dark){ :root:not([data-theme="light"]) }`

Any new token must be written in all three. An inline `<head>` script reads
`localStorage["emmmail-theme"]` to avoid a flash, and the toggle sets `data-theme` on `<html>`.

## Specificity and source order
The Buttons CSS section is emitted **after** Mailbox, so a lone class loses the tie. These
were paid for twice:

- `.form-group label.switch`, not `.switch`
- `.btn.btn-pill` and `.btn.sync-btn`, not `.btn-pill` / `.sync-btn`

A stray `margin-bottom` also knocks a flex-centred control off centre.

**`.hidden` must be `.hidden, .compose-row.hidden`.** `.compose-row`'s `display:flex` ties on
specificity. It was undefined for a long time, which is why the Cc/Bcc rows always showed.

## Destructive actions look ordinary
`.btn-danger-soft` / `.btn-warn-soft`, like `.menu-danger` and `.folder-del`: they colour only
on hover. No filled red buttons.

## Icon-only controls need `data-tip` + `aria-label`
Tooltips are `.tip::after { content:attr(data-tip) }` — **never `title`**, because CSS cannot
suppress the native bubble and the two stack. Sidebar tips hang below-right
(`overflow-y:auto` clips a centred one). All tips die under `hover:none`, because a tap sticks
`:hover`.

## Measure toolbars, do not guess
Seven labelled read-view buttons needed 810px against 808px of card, so Unread/Move/Spam/Delete
are icon-only. `.btn-label` spans are hidden under 620px, so a phone gets Back + icons on two
rows.

## The bulk bar lives in the mailbox head
As a row under the head it pushed the list down on the first pick. It is now a flex
item between "Select all" and the toolbar, with `flex:1 1 0` so it can never force a
wrap, `min-height:42px` (the toolbar's height) and `justify-content:flex-end`. The bar has
no count: the select-all label is the count ("Select all", then "18 selected"). The head is a
`container-type:inline-size` container, because the sidebar comes and goes at 900px and
a viewport query guesses wrong on either side of it. Measured, not guessed: the
labelled bar needs ~1000px of head, the icon bar ~780px.
- `@container (max-width:1020px)`: the bar buttons are icons.
- `@container (max-width:800px)`: the toolbar hides while the bar is up.
- `@container (max-width:600px)`: the toolbar always takes its own line and the bar
  takes that line, so both states are two lines. The search shrinks (`min-width:140px`),
  or a 320px phone breaks the toolbar into two lines and the bar jumps 42px.
Verified at 14 widths from 1700 to 320px: head height and list top identical with the
bar up and down. The one exception is Trash on a ~320px phone, where Empty Trash can
still wrap the toolbar.

## Custom checkboxes
`.mail-pick` and `.mail-pick-all` are round: `appearance:none` and a clipped `::before`
tick in `var(--on-accent)`. Not a data-URI SVG, because that token differs per theme.
The `::before` size is in px: it is a grid item of the box, so a percentage resolved to
zero and the tick never drew.

## Touch: nothing looks hovered
A tap leaves `:hover` stuck on the row it touched, and the old `@media (hover:none)` rules
showed every picker and every row action, so a touch list looked hovered on every row.
Now the hover reveals sit in `@media (hover:hover)`, and on touch an idle row shows
neither. A touch user starts a selection by tapping the avatar: the picker is over it
at `opacity:0`, which still takes the tap. From the first pick, `:has()` shows every
picker, and only the PICKED rows show their actions. Hidden actions get
`pointer-events:none` on touch, because `opacity:0` still receives a tap, so a hidden
Delete would fire from an empty-looking spot.

## Toasts
Notifications are floating toasts in a fixed `.toast-container` (`position:fixed`, so no
layout shift; top-centre via `left:50%` + `translateX(-50%)`, `z-index:200`). Server flash
(`render_page`), login flash and `rowError()` all feed `win.toast()`. The static
`.alert` / `.flash` / `.row-error` blocks are gone. Auto-dismiss is 4s/6s
(`win.__toastMs` overrides it for tests).

**The toast background is opaque (`--surface`), and the type rides on the border**
(`--success` / `--danger`). It was `--success-soft` / `--danger-soft`, which are
`rgba(...)` at 12–20%, so the list or the reader showed through the message text. Do not
colour the toast *text* instead: `--success` on `--surface` is 3.7:1 at `.88rem`, so that
trades a translucency bug for a contrast one.

## Modals
`showModal()` on a `<dialog>`. The confirm button carries `autofocus` — otherwise `showModal()`
focuses the first focusable (Cancel), and a phone opens scrolled to the end. Folder, move and
trusted-sender dialogs keep their input/select/Close focus by design.

## Composer
- `flex-end` on the actions row, plus `.compose-discard { margin-right:auto }`.
- `.btn-sm { height:32px }` — a text-only Discard collapsed to 27px next to 18px SVG siblings.
- Autofocus To on a blank compose, else the body.
- On a phone, Discard / Attach / Save draft are icon-only via `.btn-label` +
  `data-tip`/`aria-label`; only Send keeps its label. The phone rule repeats
  `.btn-sm:not(.compose-attach-btn)` to outrank the desktop `min-width:112px`.
- `.compose-actions` is `position:sticky; bottom:-1px` — the **document** scrolls, not
  `.mailbox`. The -1px is load-bearing: a stuck row is never fully visible, so an
  IntersectionObserver at threshold 1 toggles `.stuck` (separator + shadow).
- `html:has(.compose-actions) { scroll-padding-bottom }` stops the browser scrolling the caret
  to the window edge, under the stuck row.

## Reader toolbar
`reply_default_is_all()` decides which reply gets `btn-primary`: Reply all only when it would
add somebody a plain reply drops. **Counting raw recipients is wrong** — the user's own
address is on every message they receive.

`.row-error` is rendered in the reader, not just in the list, so a refused action is not
silent.

## Folder links
Folder links carry `class="folder-link"`, and clicking one calls `markBusy()` so its icon
spins and the top bar runs. `pageshow` / `clearBusy()` restores it.

## Unread hover
`.mail.unread:hover` must not rest on the same `--accent-soft` as the row, or hovering a new
message is invisible. There is a separate `--accent-soft-hover` token (light .24 / dark .30) in
all three theme blocks.

## Settings option rows
Every option with a control (switch, button, select) is a `.form-group.settings-toggle`: text
on the left, control on the right. `.settings-toggle + .settings-toggle` draws the divider, so a
new row placed next to the others gets one with no extra CSS. A select in a row needs
`.form-group.settings-toggle select { width:auto }`, because `.form-group select` (0,1,1) sets
`width:100%`.

The text column is `flex:0 1 32rem`, so every description wraps at the same measure and the
controls line up as a second column. A row with more than one control (Remote images:
*Trusted senders* + the switch) wraps them in `.settings-control`.
