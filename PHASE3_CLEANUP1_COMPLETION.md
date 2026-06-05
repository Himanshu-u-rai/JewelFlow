# Phase 3 — Cleanup Task #1 Completion

> Retirement of the three residual legacy compliance CSV export endpoints,
> completing the migration to the reporting spine. Executed in the required order
> (verify spine equivalents → rewrite golden test → migration note → verify →
> retire). **No Accounting report implemented yet.**
>
> **Date:** 2026-06-05 · **Branch:** `feature/report-export-architecture`

---

## 1. Routes removed

| Route name | URL | Status |
|---|---|---|
| `report.gstr1.csv` | `GET /report/gstr1/export` | **removed** (`routes/web.php`) |
| `report.cn-register.csv` | `GET /report/cn-register/export` | **removed** |
| `report.day-book.csv` | `GET /report/day-book/export` | **removed** |

Verified: `php artisan route:list | grep '\.csv'` for these three → **none found**. (The screen routes `report.gstr1` / `report.cn-register` / `report.day-book` remain, served by the spine `ReportScreenController`.)

## 2. Controllers retired

| Method | File | Status |
|---|---|---|
| `gstr1Csv()` | `TaxReportController` | **removed** (legacy banner-CSV via `CsvReportExporter`) |
| `creditNoteRegisterCsv()` | `TaxReportController` | **removed** |
| `dayBookCsv()` | `ReconciliationReportController` | **removed** |

Both controller classes remain active for their other routes (GSTR-3B-adjacent/non-compliance). Each removed method left a one-line breadcrumb pointing at this note. `CsvReportExporter` itself stays (still used by non-compliance legacy CSV reports, Phase 3/4 scope).

## 3. Tests migrated

| Test | Change |
|---|---|
| `TaxExportGoldenTest` | **Rewritten** to validate the **spine** format instead of the legacy banner-CSV. GSTR-1 → asserts a clean **per-section ZIP** (`b2b.csv`, `b2cs.csv`, `hsn.csv`, `credit-notes.csv` + `*-meta.json`), **no `== B2B ==` banners**, exact B2B/B2CS/CN values. CN Register → clean single `text/csv`, exact header + values. Renders through the spine `CsvRenderer` (same path as the export panel). |
| `TaxServiceTest` | Dropped the two legacy `*.csv` route assertions (CSV now covered by `TaxExportGoldenTest` against the spine); screen assertions retained. |

## 4. Migration-note location

`COMPLIANCE_CSV_MIGRATION_NOTE.md` — legacy vs spine format, compatibility impact (incl. the GSTR-1 banner→ZIP breaking change for scripts), migration guidance, retirement rationale.

## 5. Export-audit verification

The spine CSV path writes a `report_exports` row per export (`ExportAuditService::write()` records report_key, profile, format, user, timestamp). The retired legacy endpoints wrote **no** audit row — so audit coverage **improved**. Verified by `ExportAuditTest` (audit/export/permission suite: **22 passed**).

## 6. Reconciliation verification

- `TaxExportGoldenTest` proves **exact data parity** on the spine output (B2B 100000/1500/1500/103000; B2CS 50000 agg; CN-GOLD-001 20000/300/300/600/20600). **No data loss.**
- `ComplianceReportsTest` (reconciliation by construction) remains green — GST ↔ GSTR-1 ↔ GSTR-3B ↔ CN register ↔ Day Book totals unchanged. **No reconciliation drift.**

## 7. Regression status

| Suite | Result |
|---|---|
| Reporting (`tests/Unit/Reporting tests/Feature/Reporting`) | **171 passed (777 assertions)** |
| Export/permission/audit/golden | **22 passed** |
| Full app suite | **540 passed, 84 pre-existing failures unchanged** (baseline) — **zero new regressions** |

## 8. Final classification

| Endpoint | Classification |
|---|---|
| `report.gstr1.csv` | **RETIRED · REPLACED BY SPINE** (clean per-section ZIP) |
| `report.cn-register.csv` | **RETIRED · REPLACED BY SPINE** (clean single CSV) |
| `report.day-book.csv` | **RETIRED · REPLACED BY SPINE** (clean single CSV) |

**Residual note (non-blocking):** the orphaned legacy Blade views (`reports/tax/gstr1.blade.php`, `reports/tax/cn-register.blade.php`, `reports/day-book.blade.php`) still contain `route('report.*.csv')` links but are **not served by any route** (their screen routes are spine-served) — unreachable. They remain cleanup candidates for a later dead-view sweep; not part of this CSV-retirement task.

---

## Commit IDs & test counts

- `6a15fbc` — Phase 3 readiness + residual CSV retirement audits (prior).
- *(this change)* — Cleanup #1: retire 3 CSV routes + 3 controller methods, rewrite `TaxExportGoldenTest` to spine, migration note, completion doc.

Reporting **171 passed**; full suite **540 passed / 84 baseline failures unchanged**.

---

*Cleanup Task #1 complete. Next: begin the first Accounting report — Metal Movement Ledger — per `PHASE3_ACCOUNTING_RECONCILIATION_MATRIX.md`, reconciling against `vault:reconcile` by construction.*
