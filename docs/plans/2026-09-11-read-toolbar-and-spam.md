# Read-view toolbar, default reply, Mark as Spam

status: done

## Target

```
[Back]   [Reply][Reply all][Forward] | [Mark as unread] | [Mark as Spam] [Delete]
```

## Decisions (confirmed)

1. `[Mark as read]` becomes **Mark as unread, then back to the folder list**.
   `handle_read()` marks the message read before the page renders, so a literal
   "Mark as read" is a no-op, and a reload would re-mark it read.
2. **Default reply** = `btn-primary` (filled accent), like Send. Reply all earns
   the slot only when it would address somebody a plain reply leaves out.
   Both buttons stay visible always (existing decision, `tests/unit.php:1375`).
3. **Delete and Mark as Spam look like normal buttons** and only turn red /
   orange on hover, matching `.menu-danger:hover` and `.folder-del:hover`.
   Delete loses its filled `btn-danger`.
4. **Mark as Spam is built now** (no prior art: no route, no handler, no
   `findJunkFolder()`, no generic move).

## Steps

| # | Change | Verification |
|---|---|---|
| 1 | `--warning`, `--warning-soft` tokens in all **three** theme blocks (`:root`, `[data-theme=dark]`, media clone) | unit: token present 3x |
| 2 | `.btn-danger-soft:hover` / `.btn-warn-soft:hover`, `.actions-sep` | unit: css_inline contains rules |
| 3 | `reply_default_is_all(array $email, string $me): bool` — pure | unit: solo -> false, cc/extra To -> true, self excluded |
| 4 | `view_read(..., string $me = '')`, toolbar rebuilt in sketch order with separators; primary reply chosen by step 3; Delete restyled | unit: primary on the right button, order, separators |
| 5 | Read-view avatar gets `avatar_tone()` | unit: `class="avatar av-` |
| 6 | `MailImap::isJunkFolder()` (pure static), `findJunkFolder()`, `moveToJunk()` | unit: pure matcher; IMAP methods mirror `findTrashFolder`/`moveToTrash` |
| 7 | `?page=spam` route + `handle_spam()` (POST, CSRF, `selectFolder`, `cache_apply_move`, flash, redirect) | unit: route reachable in source; handler redirects so it is untestable in-process (memory invariant) |
| 8 | `win.markUnreadBack(uid, folder)` — POST then go to the folder, never reload | js suite |
| 9 | README route list + `docs/memory.md` | read back |

## Follow-ups done in the same pass
- Labels measured, not guessed: 7 text buttons needed 810px against 808px of card,
  so Unread/Spam/Delete became icon-only `.btn-icon`.
- Tooltips: one `.tip` component reading `attr(data-tip)`; all 9 `title=` sites
  converted, `aria-label` added to every icon-only control.
- Phone: `.btn-label` hidden under 620px, so the bar is Back + 6 icons.

## Notes

- `view_read()` gains an **optional** 4th param: three existing test groups call
  it with 3 args.
- `handle_read()` already computes `$me` (`index.php:3552`); `render_read()` must
  forward it.
- No IMAP-stub coverage exists for `moveToTrash`/`findTrashFolder`, so the new
  IMAP methods are covered by their pure matcher plus a source-shape assertion.
