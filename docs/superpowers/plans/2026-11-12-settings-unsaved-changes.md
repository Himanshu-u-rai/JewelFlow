# Settings Unsaved Changes Implementation Plan

> **For agentic workers:** Execute inline using superpowers:executing-plans; do not delegate or commit the user's existing worktree.

**Goal:** Warn before losing changed Settings values, using Keep editing / Discard & leave, without automatic saves.

**Architecture:** Track the current successful controls of editable Settings POST forms in memory. Compare against their initial values at navigation time, so reverting a field removes the warning. Use a Settings-only native dialog for in-app navigation and the standard browser beforeunload warning for refresh/close. Preserve independent form submissions; warn if saving one form would discard another form's edits. Reset only on new server-rendered forms, not on resize or failed saves.

**Tech Stack:** Existing Blade, CSS, native dialog/FormData, Turbo 8, Node test runner.

## Approved interaction

The user approved a themed Unsaved changes popup with Keep editing and Discard & leave. Escape/backdrop retains edits. No automatic save. Refresh/close wording is browser-controlled. Applies only to Settings; POS, Admin and Dhiran remain unchanged.

### Task 1: Guard regression tests

- [x] Add `tests/Frontend/settings-unsaved.test.mjs` testing actual guard logic with small DOM/event boundary fixtures: changed/reverted controls; excluded GET/action-only forms; independent saves; failed saves; links/Turbo requests; beforeunload; history restoration; no duplicated listeners.
- [x] Run `node --test tests/Frontend/settings-unsaved.test.mjs`; confirm failures because the feature is absent.

### Task 2: Settings-only implementation

- [x] Create `resources/js/settings-unsaved.js`; export the initializer used by the tests, keeping snapshot private. Use native form controls/FormData, no storage, timers, autosave or new dependencies. Initialize from `resources/js/app.js` beside the existing Settings initializer.
- [x] Add a translated dialog partial in `resources/views/partials/settings/unsaved-dialog.blade.php`, include outside the replaced frame in `resources/views/settings.blade.php`. Use native focus containment and restore focus on cancellation.
- [x] Add scoped responsive dialog styles to `resources/css/settings-workspace.css`, using the incumbent white/orange palette and 44px actions. No motion is introduced.
- [x] Verify changed values survive Cancel, and Discard resumes exactly the original navigation once. Normal saves retain native validation and existing routes. Do not mark unrelated forms saved.

### Task 3: Verification and handoff

- [x] Run Settings frontend tests and full frontend regression suite; run isolated Settings PHP UI/Turbo/preferences/return-policy tests against port 5432, never preview port 55432.
- [x] Build with `npm run build -- --emptyOutDir false` to preserve the live manifest.
- [x] Check in-app browser desktop/320px: clean switching; dirty Return Policy switch cancellation/acceptance; revert; dirty other-page link; Website/Payment revisits. Check browser unload protection through unit event boundaries without closing the user's live tab. Never submit live settings.
- [x] Run `git diff --check`; document actual results and limits. Leave preview running; no commit/stage/deploy.

## Results

12 new guard regressions passed after red/green cycles. Full frontend suite: 350 passed, 22 existing skips. Isolated PHP suite: 31 passed, 352 assertions. Build and whitespace checks passed. Actual preview: Keep editing/Escape retain Return Policy edits, reverting is clean, Discard switches tabs, Website edit warns on Product preferences full-page link, and the discarded tagline remains unchanged after reload. Dialog fits 320px with 44px actions; clean Settings tab clicks did not warn. Native refresh/close and Turbo indexed Back/Forward are tested at event boundaries, not by closing the user's tab; physical mobile/browser-native prompt appearance is not verified. No live saves, stored drafts, dependencies, commits or deployment.
