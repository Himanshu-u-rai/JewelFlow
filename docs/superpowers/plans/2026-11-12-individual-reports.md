# Individual Retail Reports Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans inline. Do not delegate or touch POS, Admin or Dhiran.

**Goal:** Make every report linked by the Retail reports hub readable, responsive and functionally consistent, without changing accounting definitions.

**Architecture:** Keep the existing canonical dataset services, column policy, permission gates and export pipeline. Replace the four duplicated generic screen variants with one shared screen, scoped CSS, a reusable filter partial and keyboard-accessible sorting. Keep specialist report data sources intact and adapt their views to the same surfaces and filters.

**Tech Stack:** Laravel Blade/PHP, Alpine, shared responsive-filter-panel, flatpickr, native JavaScript, existing PHPUnit/Node test suites.

## Checkpoint, 2026-11-12

Owner follow-up: export setup now opens in a shared desktop dialog/mobile bottom
sheet rather than navigating away. One extracted server form serves the frame
and standalone fallback. Existing downloads/queued feedback/presets/validation
are retained; tests and signed-in desktop/390/320px popup checks run. This does
not complete the broader all-report visual checklist. See the handoff follow-up.

Sections 1 and 2 implemented and covered by regression tests. Baseline Reporting
suite passed264 tests/1365 assertions before edits. Shared tables now include
screen-only row numbering; footer cells retain exact canonical column order and
body-cell numeric alignment/edge padding. Sorting reindexes without changing
financial values. Repairs numbering continues across pages.

Confirmed and corrected category-sales source bug using the existing finalized
sale/accounting-date scopes; regression was observed failing before the fix.
No live records changed. Expanded tests cover populated specialist screens and
invalid filters. Fresh final logs: `/tmp/jf-reports-complete-php.log`,
`/tmp/jf-reports-complete-frontend.log`, `/tmp/jf-reports-complete-build.log`.
Frontend330 passed/22 existing skips; build passed. Final combined PHP rerun
passed290 tests/2478 assertions. Diff whitespace check and static layout detector
passed with no findings.

- [x] Baseline, shared screen, controller validation and regression coverage.
- [x] Specialist views, export scope usability and finalized-sales correction.
- [x] Row numbering, keyed total cells, numeric alignment and sorting tests.
- [x] Combined PHP, all frontend tests, build and static layout checks.
- [x] Handoff updated; existing Vault changes preserved.
- [ ] Signed-in desktop/intermediate/mobile visual and interaction verification.

Section3 browser portion remains **pending owner sign-in**. Both in-app browser
tabs show login; no connected Chrome session. No authentication bypass. Static
layout scans and automated tests do not replace visual responsive checks. Leave
browser-dependent tasks below unchecked until actually verified. Handoff updated.

## 1. Baseline and shared screen

- [ ] Run `APP_ENV=testing DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=jewelflow_testing php8.2 vendor/bin/phpunit tests/Feature/Reporting` and record pre-existing failures. Never run against preview55432.
- [ ] Add `tests/Feature/Reporting/IndividualReportUiTest.php` for custom/named FY validation, legacy bookmark/export scope, as-of defaults, Sales Register shared rendering, and per-definition column/value/total retention. Invalid periods must produce422, not500.
- [ ] Modify `app/Http/Controllers/Reporting/ReportScreenController.php`: validate only period inputs before resolving them, expose the resolved period/preset/export query to Blade, preserve all dataset math and authorisation. Point the existing Sales Register route at this controller with its existing key/gates.
- [ ] Replace `resources/views/reporting/reports/generic-screen.blade.php` with one shared screen; add filter/table partials in `resources/views/reporting/partials/`. Render each canonical section once, including totals/empty states. Keep cash summary figures sourced from its summary section; explain that cash filters narrow ledger rows, not the true drawer summary. Never recalculate totals in Blade.
- [ ] Add `resources/css/individual-reports.css` imported in `resources/css/app.css`: matching surfaces/KPI pills, scoped mobile header, bottom filter sheet, contained tables, sticky headings, mobile labelled cells, large-value wrapping. Multi-section reports retain page scrolling.
- [ ] Add `resources/js/report-screen.js` and import/initialize it from `resources/js/app.js`. Sorting is native-button driven and stable, preserves raw cell values, does not recalculate running balances, and initializes safely across Turbo visits. Add runnable Node tests in `tests/Frontend/report-screen.test.mjs`.

## 2. Specialist screens and export usability

- [ ] Update `resources/views/report_repairs.blade.php`, `resources/views/reports/reference-prices.blade.php`, `resources/views/retailer-reports/{stock-aging,sellers,occasions}.blade.php` to matching shared surfaces, compact summaries, accessible table/row actions and mobile filters. Keep current collection/pagination/permission semantics. Explain seller figures are by category, and repair final charges are not collected cash.
- [ ] Check `resources/views/reporting/export-panel.blade.php` for valid custom/FY fields, selected scope/profile parity, rigid columns, access gates and return navigation. Preserve export submission, preset management, queue/audit/download behavior; no real customer exports in browser.
- [ ] Add specialist render/filter regression assertions to the targeted PHP test. Include long/empty values and scope restrictions; preserve current services.

## 3. Verification and handoff

- [ ] Run the complete Reporting PHP suite plus affected Retailer/Repairs tests serially on disposable5432; run `node --test tests/Frontend/*.test.mjs`, `npm run build`, and `git diff --check`.
- [ ] Run `/home/himanshu/.agents/skills/impeccable/scripts/impeccable detect --json --scope layout` against changed UI files. Inspect rendered desktop, intermediate and320/390px screens; verify filters/calendar, clear/apply, custom/FY, sorting, empty states, long amounts and exports using isolated tests.
- [ ] Browser requires owner sign-in. Do not bypass authentication or mark visual checks complete if blocked. No live finance/stock/repair writes, no production/staging changes.
- [ ] Update `docs/handoffs/table-consistency-takeover-2026-11-01.md` with route coverage, checks actually run and any remaining risk. Keep prior Vault changes intact and separate from report scope.
