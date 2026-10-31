# Data-table consistency: notes for review

> Covers the first two code commits only. The current state, later corrections and the takeover instructions are in [table-consistency-takeover-2026-11-01.md](table-consistency-takeover-2026-11-01.md); where the two differ, that file wins (e.g. the exclusion list below was reduced, Repairs is no longer pinned, row actions are icon-only).

Branch `feature/table-consistency`, based on the integrated UI branch at `3df1baf7` (icons + companion web work). Two code commits, kept separately reviewable:

| Commit | What |
|---|---|
| A | The shared, scoped table pattern (`resources/css/tables.css`) and Customers, Invoices, Repairs on it |
| B | Every other applicable data table, the retired page-level table CSS, numeric alignment |

```bash
git diff 3df1baf7 <A>      # the pattern + the three representative tables
git diff <A> <B>           # the rest
```
Scope is data tables only (Retail, Dhiran, platform admin). No cards, forms, fonts, navigation, buttons or JavaScript were changed, and no new request: `tables.css` is imported by `app.css`.

## The pattern
Opt-in: nothing applies unless a `<table>` carries `.jf-table`. Selectors use child combinators (`.jf-table > tbody > tr > td`), so they beat the per-cell Tailwind utilities and the older global `.content-inner table` padding rule by specificity, without `!important`: a table is migrated by adding a class, not by rewriting its cells. The older global "Data tables" block in `app.css` now excludes `.jf-table` (`table:not(.jf-table)`), so every other table keeps exactly what it had.

- **Header:** 12px / 600 / 0.05em uppercase, slate-600 on slate-50, one separator; a header without an alignment class starts left (browsers centre `<th>`).
- **Cells:** 14px vertical, 12px horizontal (+8px on the first/last cell), one row separator, zebra, hover and focus-within tint; links, buttons and selects get a 2px focus ring.
- **Numbers:** right-aligned cells (`text-right`/`jf-num`) get tabular figures and never wrap; headers follow the cells.
- **Actions:** `.jf-table-actions` wraps inside its cell instead of widening the column; 40px targets on touch screens. `.jf-table--sticky-end` pins the last (action) column at the right edge of the scroll container (used on Repairs and Invoices).
- **Empty / no-match:** `td.jf-table-empty` (padding, alignment, muted text, 40px icon).
- **Long descriptive text only:** `.jf-cell-clip` (ellipsis, `title` for the mouse, `tabindex="0"` so keyboard focus or a tap expands it). Not applied to any amount, identifier or name; no table currently needs it.
- **Narrow screens:** tighter cells, `min-width: 720px`, and the table scrolls inside its container; existing mobile cards are untouched.
- **Palette** by custom properties: Retail slate (the pattern the inventory, repairs and invoices tables had converged on), Dhiran warm hover/zebra, platform admin its cool-grey header (`#f1f3f9`) with the amber row hover.

## What moved
169 data tables: Retail 126 (including the 4 Customers tabs, Invoices and Repairs), Dhiran 15, platform admin 28; 115 view files, one class added per table plus the alignment and empty-state classes. The generic header/body/row/cell CSS of these page families was retired (items, vault, ledger, karigars, job orders, karigar invoices, vendors, installments, schemes, customer detail, repairs report, invoice detail, admin); column-width and class-specific rules stay. CSS: 228 lines added (`tables.css`), 787 removed.

Repairs: the action cell wraps (View, status select, Edit, Bill all kept, labels unchanged) and is pinned, Weight is right-aligned. Invoices: the fixed-width columns and `!important` type overrides that clipped the Actions column are gone; all ten columns fit at 1440px.

## Intentional exceptions (not migrated)
- **Print / PDF / email documents and receipts** (18 tables): invoice print, quick-bill print, karigar-invoice print, challans, Dhiran receipt/closure/forfeiture, scheme and exchange receipts, platform-invoice email, job-order return document.
- **Editable entry grids** (17): POS tablet grid, historical manual entry, the opening-balance (onboarding) screens, job-order receive, settings pricing.
- **Bill / invoice line-item previews** (7): invoice detail preview tables, quick-bill items, karigar-invoice lines and payments: they mirror the printed bill.
- **Key-value summary tables** without a header row (3).
- Columns that are identifiers or row numbers (`#`, phone, codes) stay left-aligned; centred counts (Active/Closed/Forfeited) stay centred; centred **amounts and weights** whose header names them were moved right.

## Verification
Performed locally against a baseline server at `3df1baf7` (own cookie names, same demo data), Retail owner, Dhiran owner and platform admin:
- **Audit of 214 page views** (107 pages, desktop 1440 and phone 390) with a DOM audit of every table: table count unchanged on every page; header typography variants 13 → 5, header padding variants 15 → 5, cell padding variants 14 → 5, header/cell alignment mismatches 6 → 1, tabular-number tables 14 → 40; no page-wide overflow on any page, desktop or phone; the remaining non-`.jf-table` tables on those pages are the two print/receipt tables. The 5 remaining header variants are the document-like tables and tables with no rows in the demo data.
- **Containment:** the only tables wider than their container (Repairs, sales register, admin plans) scroll inside it; no table is wider than the phone viewport outside a scroll container. Phone views still show the same cards (table counts equal).
- **Interactions:** Tab reaches the first action in Repairs, Invoices and Customers with a visible 2px focus ring; long text (about 240 characters) in the first row of Repairs, Customers and Invoices causes no page overflow and nothing is clipped; the clip helper expands on focus; empty states render (invoices search with no result, schemes); a cashier account (not the owner) shows the same tables with no overflow.
- **Dropdowns:** no migrated table contains an absolutely positioned menu (the three `<details>` panels in admin and import tables expand in-flow); native selects only. Checked by reading the markup; the demo data has no rows that open those panels, so it was not exercised on screen.
- **Tests:** full suite on the code commit: 3851 passed, 1 skipped, 0 failed; all views compile and every compiled view passes `php -l`.
- No performance benchmarking was done for this batch (not requested); no extra request or JavaScript was added (`tables.css` is imported by `app.css`).

Not checked: tables that had no rows in the demo data were audited by class and computed style only, not with real rows; the Dhiran and platform-admin pages were checked as the local demo accounts only; nothing was run on staging or a physical phone.

## Remaining issues
- Invoice row actions are 34px icon links (touch target); that is the button redesign that comes later.
- Vault lot columns are centred numbers whose headers do not name an amount; they were left centred.
- `/admin/2fa/manage` returns 500 on the baseline too (unrelated, already reported).
