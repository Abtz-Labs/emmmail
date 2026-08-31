# Frontend UI Plan

**Date:** 2026-08-26
**Status:** done

## Context

The backend API is complete (42 endpoints, 479 tests). Now we need the frontend UI. The old implementation (`index.old.php`) has a complete SPA frontend with HTML/CSS/JS in a single `serveFrontend()` function.

## Approach

- Server-rendered HTML from `serveFrontend()` in `index.php`
- Single-page application with hash-based routing
- CSS variables for theming (dark/light)
- Vanilla JavaScript (no frameworks)
- Tabler Icons via CDN
- Mobile-first responsive design

## Architecture

```
serveFrontend() → HTML string
├── <head> — meta, CSS, theme script
├── <body>
│   ├── #app — main container
│   │   ├── .topbar — header with search, avatar menu
│   │   ├── .sidebar — folders, tags, account switcher
│   │   ├── .pane-list — message list
│   │   ├── .pane-detail — thread/message view
│   │   ├── .compose-overlay — compose window
│   │   ├── .bottom-tabs — mobile navigation
│   │   └── .toast-container — notifications
│   └── <script> — App object with all logic
```

## Implementation Phases

### Phase 1: Shell + Login + Setup
- Basic HTML shell with CSS variables
- Login page
- Setup wizard
- Theme toggle

### Phase 2: Core Views
- Sidebar (folders, tags, account switcher)
- Message list
- Thread view
- Search

### Phase 3: Compose + Settings
- Compose window (new, reply, forward)
- Settings page
- Contacts page

### Phase 4: Polish
- Keyboard shortcuts
- Animations
- Error handling
- Loading states

## TDD Approach

For the frontend, we'll test:
1. `serveFrontend()` returns valid HTML
2. HTML contains expected elements (login form, sidebar, etc.)
3. CSS variables are defined
4. JavaScript App object is present
5. API integration works (already tested)

## Files

- `index.php` — `serveFrontend()` function
- `tests.php` — frontend tests
