# Bulk selection polish

status: done (manual pass in a live session not run)

Four tweaks to the bulk-actions UI (`2026-10-02-bulk-actions.md`), from user feedback.

## Steps

1. **Round checkboxes.** `.mail-pick` and `.mail-pick-all` use `appearance:none`,
   `border-radius:50%`, and draw the tick with `::before` + `clip-path` in
   `var(--on-accent)`. A data-URI SVG cannot read a token, and `--on-accent` is white in
   light and `nord0` in dark. `:indeterminate` draws a dash.
2. **"Select all" label.** The head checkbox is wrapped in `<label class="pick-all">`
   with the visible text "Select all", separated from the title by a left border.
   The aria-label stays ("Select all messages" contains the visible name).
3. **Space selects the cursor row.** No shortcut uses Space. Space acts only when a
   cursor exists, and not when focus is on a button or a link other than the row link.
   Otherwise the native action (scroll, press) stays. `x` stays as the alias.
4. **The bar moves into the head**, between "Select all" and the toolbar, so showing it
   does not push the list down. `.mailbox-head` is an inline-size container:
   - wide: everything on one line, labelled buttons;
   - below 1020px: the bar buttons become icons;
   - below 800px: the toolbar hides while the bar is up;
   - below 600px: the toolbar always takes its own line, and the bar replaces it on
     that line, so the head keeps two lines in both states.
   `.bulk-bar` has `min-height:42px` (the toolbar height) and `flex:1 1 0`, so it never
   wraps.
5. **The label is the count.** The select-all text reads "Select all" with nothing
   picked and "18 selected" after; it is the live region (`role="status"`). The bar
   keeps only the four buttons, which frees ~150px, so the tiers drop to 1020/800px.

## Verification
- Red, then green: `php tests/unit.php`, `php tests/js.php`, `php tests/run.php`.
- Browser: a static render at 1400, 1000, 760 and 375px wide, before and after a pick.
  The list must not move.
- Docs: README (`x`/Space), `docs/knowledge/frontend.md`, `docs/knowledge/css-ui.md`,
  `docs/memory.md`.

## Outcome
Steps 1-4 done. `just test` 2820 passed, 2 smoke skips. A static render with the real CSS
was measured at 12 widths (1700 to 320px): head height and list top do not change when
the bar comes up. Two findings from that pass: the first tiers (1060/890px) were guesses
and wrapped at 1400px, so they are now measured (1140/920px); and at 320px the toolbar
broke into two lines, so on a phone the search shrinks. The `::before` tick used
percentages and drew nothing; it is in px now.

Not verified in a real logged-in session: Space with a real focus ring, the label click,
and both themes on a phone. Known edge: Trash on a ~320px phone can still wrap the
toolbar (Empty Trash + sync + search).

Step 5 (follow-up): the count moved into the select-all label, which freed ~150px.
The tiers were re-measured and lowered to 1020/800px. All 14 widths, 1700 to 320px,
keep the same head height with the bar up and down, and "200 selected" fits at 320px.

Step 6 (follow-up): on touch, rows no longer all look hovered. The hover reveals are
scoped to `(hover:hover)`. On touch, the pickers appear from the first pick (a tap on the
avatar) and the row actions only on picked rows. Hidden actions take no tap. Checked
with `hover:none` forced in a static render: idle row 0/none, picked row 1/auto.

Bug fix: bulk mark read/unread no longer removes the rows. The success path had the
row-removal loop copied from the delete/move path; a flag change keeps the message in
the folder, so only the selection is dropped. Also `pickReset()` now clears
`indeterminate` as well as `checked`, or the "Select all" head kept its dash after
every bulk action. Covered by S71 and the new S80.

Bug fix: a read change (single toggle or bulk) no longer turns the paperclip into an
envelope. `setReadIcon()` took the row's first `<use>`, which is the subject's
paperclip on a message with an attachment; it now reads the read button's own icon.
Covered by S81.

Tweak: bulk mark read/unread drops the selection the moment the rows repaint, not
when the server answers. A refusal still rolls the rows back but leaves any selection
made in the meantime. Covered by S71 and S82.

Tweak: `pickReset()` now unchecks the row pickers as well as the head box. `checked`
is per-box state, so a deselected row kept a ticked box and the next hover showed it.
S71 covers it.
