# Web UI work: takeover handoff (icons + tables), 2026-11-01

For Codex, taking over the "JewelFlows UI work" chat. Local only: nothing here has been deployed or tested on staging, and nothing touches production, the mobile app, APK/AAB, OTA, OTP or SMTP. Public repo: no credentials in this file.

- **Branch:** `feature/table-consistency` (pushed, no force). **Base:** `3df1baf7` (the integrated UI branch). **Worktree:** `/home/himanshu/Desktop/jewelflow-worktrees/ui-integration`.
- **Commits on top of the base:** `2f928975` shared table pattern + Customers/Invoices/Repairs, `5fec8462` all other tables, `7d4758e3` first review notes, `9971976b` Customers filter row, `a71ea974` action-cell heights, `5032f245` table round 2 (below), then this handoff. `git log 3df1baf7..HEAD` is the full list.
- The older [table-consistency-review-2026-11-01.md](table-consistency-review-2026-11-01.md) describes only the first two commits; where it differs from this file, this file is current.

## What the branch contains
- **Icons:** every icon in Retail, Dhiran and platform admin is a Lucide glyph drawn through `<x-icon name="..." size="..">` (Blade precompiler → `App\View\Icons::svg()`, 150 glyphs in `app/View/Icons.php`, fixtures in `tests/Fixtures/lucide-1.66.0`); icons built by scripts use `@json(\App\View\Icons::svg(...))`. 23 inline SVGs stay on purpose (brand marks, logos/barcodes, printed-document seals). From `feature/lucide-icons-batch1` (`090436da`, merged as `fe4d1a07`) plus `d63d542e`, `5d6e336e`, `3df1baf7`.
- **Companion-web changes in the ancestry:** `feature/mobile-companion-api` at `b1d7f5c4` (companion API and web parity, 2026-10-17..31, including the web Record-payment retry fix) and its 23 previously uncommitted UI files, committed as `4502088c` (header notice bell, quick-bill pricing mode, repair edit purity/revisit, reorder, dashboard).
- **Tables:** 214 `<table>` tags in `resources/views`: 177 on `.jf-table`, 4 on `.jf-grid` (header look only), 33 intentionally or knowingly left (see below).

## Shared table styles: entry points
Everything is in `resources/css/tables.css`, imported by `resources/css/app.css` (Dhiran and admin layouts load it through `app.css` too). Opt-in by class; selectors use child combinators so they beat per-cell Tailwind utilities without `!important`.

| Class | Use |
|---|---|
| `.jf-table-wrap` | scroll container (the table scrolls inside it, never the page) |
| `.jf-table` | header (12px/600/0.05em uppercase), cells (14px/12px, first/last +8px), separators, zebra, hover, focus ring, tabular numbers on `.text-right`/`.jf-num`, phone: tighter + `min-width: 720px` |
| `.jf-table--compact` | 8px side padding (Repairs) |
| `.jf-table--fit` | no forced min-width on phones (bill previews, summaries) |
| `.jf-table--sticky-end` | pins the last column (still used on Invoices only) |
| `.admin-table` / `.dh-app .jf-table` | platform-admin palette (light grey header) / Dhiran palette, through custom properties `--jf-t-*` |
| `.jf-table-actions` (`--center`) | wrapping action cell, every control the same height (36px, 44px on touch) |
| **`.jf-row-action`** (`--view/--edit/--neutral/--danger/--success/--primary`) | **icon-only row action with a floating label**: markup `<a class="jf-row-action jf-row-action--edit" data-tip="Edit"><x-icon name="pencil" size="16" /><span class="jf-sr-only">Edit</span></a>`; label is `data-tip` (above the button on hover and keyboard focus), accessible name stays in the hidden span; 36px, 44px on touch |
| `td.jf-table-empty`, `.jf-indent`, `.jf-cell-clip` | empty/no-match row, indented summary row, focusable expandable clip (unused so far) |
| `.jf-grid` | editable entry grids: only the header text takes the shared look; their cells, borders, inputs and keyboard behaviour are untouched |

Other entry points: the older global "Data tables" block in `app.css` excludes `.jf-table` (`table:not(.jf-table)`); the global typography rule that forces weight 500 on `.font-semibold/.font-bold` inside tables now skips `.jf-table thead th` (that rule made some headers lighter than others).

**Remaining page-specific overrides (not removed on purpose):** column widths and wrapping rules in page `<style>` blocks (Repairs: Customer/Item wrap, action cell `width: 1%`; Vendors `min-width: 820px`; karigar-invoice show `min-width: 860px`; the Customers toolbar grid override in `app.css`); mobile card lists next to the tables (untouched, they keep text buttons). **Dead CSS left behind** by the row-action conversion (no longer referenced by table cells, safe to delete in a clean-up): `customers-row-action`, `vendors-link-btn`, `sp-row-action`, `qb-action`, `karigars-row-btn`, `reorder-action-*`, `installments-show-receipt-link`, `sub-billing-view`.

## Still inconsistent or untouched, by exact file and reason
1. **`resources/views/dhiran/reports/forfeiture.blade.php`: a real screen table that was missed.** Route `dhiran.reports.forfeiture` (`/dhiran/reports/forfeiture`), a plain `w-full border-collapse` table with Tailwind header/cells. It was classified as a print document by file name and not on the crawl list. **Not migrated; first thing to do** (add `jf-table`, check numeric alignment, Principal/Written Off already right-aligned, Items Count centred).
2. **`onboarding/index.blade.php` (12 tables) and `onboarding/_staged.blade.php` (1):** header-less, inline-styled key/value lists in the one-time opening-balance wizard; no header row to share and not renderable with demo data. Untouched. (`onboarding/suppliers` is migrated.)
3. **Print / PDF / email / receipt documents, kept separate by design (20 tables):** `invoice_print` (2), `quick-bills/print` (2), `karigar-invoices/print` (2), `repairs/challan` (3), `job-orders/challan`, `job-orders/return-doc`, `returns/exchanges/receipt` (2), `schemes/payment-receipt`, `dhiran/receipt` (also shown at `/dhiran/loans/{id}/receipt`), `dhiran/closure-certificate`, `emails/platform-invoice`, `reporting/layouts/report-document` (PDF layout), `reports/gst` (the print-only summary head).
4. **Editable grids, header look only (`.jf-grid`):** `historical/_manual-form-fields` (desktop grid), `job-orders/receive`, POS `items-table` and `items-tablet-table` in `pos_customer_retailer`. Spacing, borders and inputs deliberately unchanged. **Not looked at on screen after the header change** (see "not verified").
5. **Row actions still text:** create, financial and approval actions (Pay, Collect, Approve/Deny, New loan ...) and any control whose label is dynamic (`@if` or `{{ }}` inside the control) were left as text on purpose; I did not enumerate them. Example: the `Reverse` button in `karigar-invoices/show` payments. A grep of `tbody` buttons/links with visible words is the way to review them.

Tabs, dialogs and expanded sections: the conformity crawl un-hid every hidden tab/dialog/`<details>` table on the 107 crawled pages and read its real computed styles (synthetic rows where empty); all but the Dhiran loan receipt were on `.jf-table`. Pages not in the crawl list were checked only through the static count above, which is how item 1 surfaced.

## Repairs pinned-action overlap: fixed
The sticky action column is gone (`jf-table--compact`, no `--sticky-end`), so it can no longer cover Purity or Status. Customer and Item wrap, the action cell shows icon-only View, status select, Edit, "Complete & bill" (or View invoice). Measured: at 1918px wide the table fits (no scroll); at a 1280px viewport the Repairs list card is 624px and the 940px table scrolls inside the card with nothing overlapping. Not done: further column merging to avoid that scroll at 1280.

## Bugs found, fixes, outstanding
- Fixed: Customers toolbar wrapped the Filter button under Search; Archive was 44px next to 36px links (now one height for every control); header cells centred by default (now start-aligned); numeric dates mis-right-aligned (Next Due); table headers with `font-semibold` rendered at 500 by a global `!important` rule; Vendors kept its own 11px/0.18em header and 18px/24px cells; admin dashboard "Users" column was centred under a left header; admin tables briefly got a dark header (light `#f1f3f9` now); `php -l` of compiled views is needed because `view:cache` does not catch parse errors; 3 tests asserting the old text-button markup (`HistoricalMobileUiTest` x2, `LucideFirstLocationsTest`) now assert the icon-only row actions.
- Outstanding: item 1 above; leftover dead CSS; `/admin/2fa/manage` returns 500 on the baseline too (unrelated, already reported); in the admin permissions panel the checkboxes look faint (only seen with injected markup, unverified whether pre-existing); the platform-admins table scrolls inside its card at 1440 (last column partly off-screen until scrolled: contained scroll, no overlay).

## Checks actually run
- **Tested commit `5032f245`** (the same code as this branch's HEAD; this handoff changes docs only). Quick gate (assets build, `view:cache`, `php -l` on all 383 compiled views): 0 errors, run on the same tree before the commit. Full PHP suite run on exactly that commit: 3851 passed, 1 skipped, 0 failed (22,744 assertions).
- Earlier full run on the uncommitted tree before three test fixes: 3 failed, 3848 passed, 1 skipped; the three failures were the markup assertions above; both files re-run after the fix: 27 passed.
- **Conformity crawl** (headless Chrome against the local server, desktop 1440, 107 pages as the Retail owner, Dhiran owner and platform admin, hidden tabs/dialogs revealed, synthetic client-side rows for empty tables): 73 tables, 72 on `.jf-table`, 1 print receipt, 1 flagged for alignment that is a synthetic-row artifact (real cells carry the same `is-center` class). Before the corrections: 3 non-`.jf-table` and 16 deviating.
- **Admin `<details>` panels** (platform-admins permissions and role, edition-requests approve/deny) opened with synthetic rows injected into the DOM (nothing saved): 4 of 4 not clipped, text contrast 4.6:1 or better. The real markup was copied; the injected row has fewer columns than the real one.
- **Interactions** in the browser pane: Repairs client search (match, no-match row, restore), Customers search and status filter (6 rows, 1, 0, 0 for archived), invoice list queries, the day-book report column sort ascending and descending, Tab to the first row action on Vendors (focus ring + floating label), hover label on Repairs, 44px size under a touch pointer, no page overflow at 375px on Customers, Invoices, Inventory.
- Earlier (first batch): 214 page views audited desktop + phone, interaction checks, a cashier account, long text. Details in the older review notes.

## Not verified
Staging, a physical phone, tablet width, restricted roles (cashier) for this round, populated onboarding pages, multi-page pagination (the demo has 3 invoices, page 2 renders the empty state with links), POS and Historical grids after the `.jf-grid` header change, real (not injected) admin panels, screenshots of the after state of this round (only the two admin panel shots exist), any Dhiran page interaction.

## Evidence and before/after
Folder `/home/himanshu/jf-customer-photo-evidence/ui-icons-impl-20261031/rollout/` (outside the repo): `shots/before-retail-{customers,invoices,repairs}-desktop.png` vs `shots/final-retail-*-desktop.png`; `shots/<page>-<viewport>-{base,after}.png` for the icon rollout; `audit-before.json` / `audit-final.json` / `audit-conform.json` (DOM audits); `details-open.json` and `shots/details-platform-admins.png`, `shots/details-edition-requests.png`; `diff-report.txt`. Representative changes: Repairs (pinned action column over Purity/Status → unpinned, wrapped, icon actions); Invoices (clipped Actions column and fixed widths → all ten columns fit at 1440, icon actions through the shared component); Customers (crumpled header and 44px Archive → even header, one control height); header weight (500 vs 600 on `font-semibold` headers → 600); Vendors (11px/0.18em/18px cells → shared 12px/0.05em/14px).

## Local preview
- **Preview server (left running deliberately):** `php8.2 artisan serve --host=127.0.0.1 --port=8001 --no-reload` from the worktree, in the Claude terminal tab "UI integrated 8001". Retail: `http://localhost:8001`, Dhiran: `http://dhiran.localhost:8001`, platform admin: `http://localhost:8001/admin/login`. `.env` (not committed) points at a private PostgreSQL on `127.0.0.1:55432`, database `jewelflow_mobile_demo`, data dir `/tmp/claude-1000/mobile-demo-pg` (lost on reboot); mail goes to the log, so the admin MFA code is read from `storage/logs/laravel*.log`.
- Also running, not needed for tables: the companion API demo backend on `:8000` (from the `mobile-companion-api` worktree, same private database). Stop it only if the mobile demo is not in use.
- **Demo sign-ins:** Retail owner `9000000111` (seed password, see the seeders), Dhiran owner `9000000301` (local test password kept outside the repo), platform admin `9000000100` (seed password + the emailed code), cashier `9000000113`. Never type credentials into any remote site.
- **Rebuild after CSS changes:** `npx vite build` in the worktree (the server serves `public/build`). Quick gate script: `/home/himanshu/Desktop/jewelflow-worktrees/build-logs/ui-integration-quick.sh`.
- **Tests:** `php8.2 artisan test` against `jewelflow_testing` on port 5432 (`.env.testing`); tests wipe that database, so do not run two runs at once.
- **Synthetic data:** no database rows were added for the table work. Empty tables are checked by injecting rows into the DOM: `build-logs/tables-conform.js` (all tables, hidden ones un-hidden) driven by `tables-crawl.cjs`; `admin-details.cjs` injects admin panels. Codemods used, all in `build-logs/` (outside the repo, local): `tables-row-actions.py` (dry-run by default, `--write`), `bill-previews.py`, `grids-and-summaries.py`, `css-drop-rules.py`.
- **Sessions to reuse:** the Browser pane tab on `localhost:8001` is signed in as the Retail owner; the terminal tabs and that tab belong to the Claude app, so Codex should open its own browser session and sign in with the demo accounts above.

## Next concrete task for Codex
Migrate `dhiran/reports/forfeiture.blade.php` to `.jf-table` (item 1), look at the POS and Historical grids on screen after the `.jf-grid` header change, then review the remaining text-labelled row actions (item 5) one table at a time.

## Standing instructions (owner)
Work on the web UI element by element: icons, then complete tables; no unrelated redesign. Prioritise UI/UX; no dedicated performance benchmarking. Preserve functionality, permissions, accessibility and responsive behaviour. No staging deployment or testing until the owner explicitly asks. No production, mobile-app, APK/AAB, OTA, OTP or SMTP work.
