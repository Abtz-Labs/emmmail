# Onboarding: Pre-fill Folder Mapping + First-Access Modal

**Status:** done
**Date:** 2026-09-16
**Parent:** docs/plans/2026-09-15-settings-improvements.md

## Problem

1. The folder mapping modal shows `-- Default --` for every role, even though the IMAP server already knows the correct folder names via `findSpecialFolder()`.
2. There is no onboarding. New users skip folder mapping entirely, then hit issues (wrong Sent/Trash/Junk, delete buttons missing, etc.).

## Goal

1. Pre-fill the modal with detected folder names when no user map is set.
2. Show a mandatory onboarding dialog on first access — once per email account — until the user confirms their folder map.

---

## Plan

### Step 1: Fix pre-existing `archiv` roleMap bug

**Where:** `MailImap::findSpecialFolder()` (index.php ~line 2497)

The `$roleMap` is missing `'archiv' => 'archive'`. When `findArchiveFolder()` passes keyword `'archiv'`, the lookup `folderMap['archiv']` never matches — the user's `archive` map entry is silently ignored.

**Fix:** Add `'archiv' => 'archive'` to the `$roleMap` array.

### Step 2: Server-side `detect_folder_map()` helper

**Where:** new function near `imap_for_session()` (index.php ~line 3625)

Single source of truth for auto-detection. Reuses existing `find*Folder()` methods — no duplicate name lists.

```php
function detect_folder_map(string $email): array
{
  $imap = imap_for_session();
  if (!$imap) return [];
  $map = [];
  $detected = [
    'sent'    => $imap->findSentFolder(),
    'drafts'  => $imap->findDraftsFolder(),
    'spam'    => $imap->findJunkFolder(),
    'trash'   => $imap->findTrashFolder(),
    'archive' => $imap->findArchiveFolder(),
  ];
  foreach ($detected as $role => $folder) {
    if ($folder !== null) $map[$role] = $folder;
  }
  return $map;
}
```

**Caching:** Store result in `$_SESSION['detected_folder_map']` to avoid repeated IMAP connections per request while onboarding is pending. One connection on first page load, reused after.

### Step 3: Pre-fill from server side (settings page + onboarding)

**Where:** `view_settings()` (line ~7286) and `render_page()` for onboarding.

When `protected_folders` is `{}` (no user map), call `detect_folder_map()` and pass the result as `data-default-map` on the button/modal trigger.

**In `view_settings()`:**
```php
$folderMapJson = $d['protected_folders'] ?? '{}';
$folderMapDecoded = json_decode($folderMapJson, true);
$detectedMap = [];
if (empty($folderMapDecoded)) {
  $detectedMap = detect_folder_map($me);
}
// Pass to button:
' data-default-map="' . h(json_encode($detectedMap)) . '"'
```

**Update `openFolderMap(folders, map, defaultMap)`:**
- If `map` is empty `{}`, use `defaultMap` (from server) as the pre-selected values.
- Drop the JS `autoDetectFolders()` entirely — server detection is the single source of truth.

### Step 4: Onboarding EAV key

**Where:** new EAV key `folder_setup_complete` (no schema migration).

- `db_get_setting($email, 'folder_setup_complete', '0')` — returns `'0'` for new users.
- Set to `'1'` in **both** `handle_save_onboarding()` **and** `handle_settings()` (when `protected_folders` is saved). This ensures users who map via Settings don't get re-onboarded.

### Step 5: Onboarding modal

**Where:** `render_page()` — add `<dialog id="onboarding-dialog">` after `#folder-map-dialog`.

```html
<dialog class="modal onboarding-dialog" id="onboarding-dialog">
  <h3 class="modal-title">Set up your folders</hh3>
  <p class="modal-desc">Map your email folders to the correct system roles.
     This is required before you can use Emmmail.</p>
  <div class="folder-map-rows onboarding-rows" style="margin:16px 0"></div>
  <div class="modal-actions">
    <button class="btn-cancel" type="button">Later</button>
    <button class="btn-confirm-primary" type="button">
      <i data-lucide="device-floppy"></i> Save & Continue
    </button>
  </div>
</dialog>
```

**Design:** "Later" button dismisses without saving — onboarding reappears next session. No dead end. If folder list is empty (IMAP cold start / failure), only "Later" is functional — Save is disabled until at least one selection is made.

### Step 6: Trigger onboarding on first access

**Where:** `render_page()` (line ~4855), gated on `$authed`.

```php
$onboardingPending = false;
if ($authed) {
  $email = current_user();
  $setupDone = db_get_setting($email, 'folder_setup_complete', '0');
  $page = ($_GET['page'] ?? '');
  if ($setupDone !== '1' && $page !== 'saveOnboarding') {
    $onboardingPending = true;
  }
}
```

**JS trigger:** Inject after `js_inline()` (line ~4886), not before — `$.token` and `$` must exist.

```php
if ($onboardingPending) {
  echo '<script>$(function(){ win.__openOnboarding = true; });</script>';
}
```

**In `js_inline()`:** check `win.__openOnboarding` in the `$.ready` block and call `openOnboarding()` if set.

### Step 7: `openOnboarding()` JS

**Where:** inside `js_inline()`, after `openFolderMap()`.

Reuses the same folder-map dropdown building logic (shared `buildFolderRows()` helper extracted from `openFolderMap()`), targeting `#onboarding-dialog`.

```js
function openOnboarding() {
  var dlg = doc.querySelector('#onboarding-dialog');
  if (!dlg) return;
  var folders = [];
  var defaultMap = {};
  try { folders = JSON.parse(dlg.getAttribute('data-folders') || '[]'); } catch(e) {}
  try { defaultMap = JSON.parse(dlg.getAttribute('data-default-map') || '{}'); } catch(e) {}
  buildFolderRows(dlg, folders, defaultMap);
  if (typeof dlg.showModal === 'function') dlg.showModal();
}
```

**Save handler:**
```js
$('.btn-confirm-primary', dlg).on('click', function() {
  var map = collectFolderMap(dlg);
  if (Object.keys(map).length === 0) return; // require at least one
  $.post('?page=saveOnboarding', {
    csrf_token: $.token,
    protected_folders: JSON.stringify(map)
  }).then(function(r) { return r.json(); }).then(function(d) {
    if (d.ok) win.location.reload();
  });
});
```

**Dismiss handler:**
```js
$('.btn-cancel', dlg).on('click', function() {
  dlg.close();
  // No flag set — onboarding reappears next session.
});
```

### Step 8: Save onboarding handler

**Where:** new route `case 'saveOnboarding':` in the router.

```php
function handle_save_onboarding(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Forbidden']);
  }
  $me = current_user();
  $raw = trim((string)($_POST['protected_folders'] ?? '{}'));
  $decoded = json_decode($raw, true);
  // Validate: must be an object with only allowed role keys and string values.
  $allowed = ['sent','drafts','spam','trash','archive'];
  if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE || array_key_exists(0, $decoded)) {
    $decoded = [];
  }
  $clean = [];
  foreach ($decoded as $k => $v) {
    if (in_array(strtolower($k), $allowed, true) && is_string($v) && $v !== '') {
      $clean[strtolower($k)] = $v;
    }
  }
  // Only mark complete if at least one role is mapped.
  $json = json_encode($clean);
  $mapOk = db_set_setting($me, 'protected_folders', $json);
  if ($mapOk && $clean !== []) {
    db_set_setting($me, 'folder_setup_complete', '1');
  }
  json_out(['ok' => true]);
}
```

### Step 9: Set flag from `handle_settings()` too

**Where:** `handle_settings()` (line ~4719), after `db_set_setting($me, 'protected_folders', $protectedFolders)`.

```php
// If user saved a non-empty folder map, mark setup complete.
$decodedMap = json_decode($protectedFolders, true);
if (is_array($decodedMap) && $decodedMap !== []) {
  db_set_setting($me, 'folder_setup_complete', '1');
}
```

### Step 10: Reload after onboarding save

The onboarding save handler (Step 7) does `win.location.reload()` after success. This ensures the sidebar renders with the new folder map (delete buttons, role classification).

### Step 11: Refactor `openFolderMap()` to share dropdown builder

**Where:** `js_inline()`.

Extract `buildFolderRows(container, folders, defaultMap)` from `openFolderMap()`. Both `openFolderMap()` and `openOnboarding()` call it. `collectFolderMap(dlg)` is also shared.

### Step 12: Update unit tests

Add tests for:
- `detect_folder_map()` returns correct map from IMAP folders.
- `folder_setup_complete` key is read/written correctly.
- Onboarding modal is triggered when `folder_setup_complete` is absent/`'0'`.
- Onboarding modal is NOT triggered when `folder_setup_complete` is `'1'`.
- `handle_save_onboarding()` persists map + sets flag.
- `handle_save_onboarding()` rejects empty map (does not set flag).
- `handle_save_onboarding()` rejects non-object JSON.
- `handle_save_onboarding()` rejects arbitrary keys.
- `handle_settings()` sets `folder_setup_complete` when map is non-empty.
- `handle_settings()` does NOT set flag when map is empty.
- `archiv` → `archive` roleMap fix works.
- Pre-fill: modal shows detected names when map is empty.
- Dismiss: "Later" does NOT set `folder_setup_complete`.

---

## Verification

1. `php tests/unit.php` — all pass.
2. Manual: fresh login → onboarding modal appears with detected folders pre-selected → Save → page reloads → inbox with correct sidebar.
3. Manual: second login → no onboarding modal.
4. Manual: dismiss onboarding → next session → onboarding reappears.
5. Manual: Settings → Protected folders → Manage → modal shows detected folders (or user map if set).
6. Manual: save folder map in Settings → `folder_setup_complete` set → no onboarding on next page load.
7. Manual: revoke sender from Trusted senders modal still works.

## Outcome

Verified in unit/JS suites (`php tests/run.php`: **1835 passed, 0 failed**, 2
live suites skipped):

| Step | Change | Status |
|---|---|---|
| 1 | `archiv` → `archive` roleMap fix in `findSpecialFolder()` | done |
| 2 | `detect_folder_map()` (session-cached), reuses `find*Folder()` | done |
| 3 | Server pre-fill: `data-default-map` on the settings modal trigger | done |
| 4 | `folder_setup_complete` EAV key, default `'0'` | done |
| 5 | Onboarding `<dialog>` rendered when the flag is not set | done |
| 6 | Trigger after `js_inline()` via `win.__openOnboarding` + `$.ready` | done |
| 7 | `openOnboarding()` JS, pre-filled from server detection | done |
| 8 | `handle_save_onboarding()` + `saveOnboarding` route | done |
| 9 | `handle_settings()` sets the flag when a map is saved | done |
| 10 | JS reloads after save so the sidebar picks up the map | done |
| 11 | `openFolderMap()` refactored into `buildFolderRows()`/`collectFolderMap()` | done |
| 12 | Unit tests (detect, clean, pending, archiv, pre-fill, routes) + JS S32 | done |

Deviations from the plan (all for testability or correctness):
- `detect_folder_map()` takes an optional `?MailImap` instead of a `$email`
  string, so pure unit tests can inject a folder stub.
- The pre-fill map is computed in `handle_settings()` and passed via `$d`, not
  inside the pure `view_settings()` view.
- The "drop `autoDetectFolders()`" item was moot: no such JS function existed.
- Settings save uses `folder_setup_complete` when the saved map is non-empty
  (its own validation stays unchanged); onboarding validation is shared through
  `folder_map_clean()`.
