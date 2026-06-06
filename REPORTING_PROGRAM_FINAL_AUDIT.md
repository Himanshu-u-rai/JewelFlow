# Reporting Program — Final Audit & Handoff

*Date: 2026-06-06 · Branch: `feature/report-export-architecture` · Read-only inventory. No code, routes, controllers, or migrations changed.*

Final inventory and handoff for the report-export modernization program
(`REPORT_EXPORT_ARCHITECTURE_PLAN.md`). The frozen spine —
`Dataset → ReportDefinition → Screen → PDF → Excel → CSV → Audit → Permissions` —
now carries the entire Compliance + Accounting + Owner report set.

---

## Section 1 — Program Status

| Phase | Scope | Status | Key commits | Regression |
|-------|-------|--------|-------------|------------|
| **Phase 0** | Spine foundation: contract, renderers, cross-cutting services, export pipeline, provider wiring | ✅ Complete | `ac4c0fa`, `3e3f8b9`; freeze `dee873d` | green |
| **Phase 1** | Pilot — Sales / Invoice Register (canonical dataset) | ✅ Complete | `82fddf6` | green |
| **Phase 2** | Compliance family on the spine (GST, GSTR-1/3B, CN register, day book) | ✅ Complete | `e82be7f`, `e8da0f8` | green |
| **Phase 2 Sign-off** | Repoint compliance reports to the spine; PDF provenance proof | ✅ Complete | `222a8f4` | green |
| **Phase 3** | Accounting set (7): Metal Movement Ledger, Inventory Valuation, Cash Flow, Daily Closing, Payment Reconciliation, Daily Summary, Metal Liability | ✅ Complete | `0e0d8a3` (cleanup), `e0014b7`, `19faac1`, `e0f8a39`, `9a19291`, `d1c6acd`, `66b45d9`, `0a31d9b`, audit `8e94198` | green |
| **Phase 4** | Owner set (2): Profit & Loss, Gold Balances | ✅ Complete | `913bda4`, `6358049` | green |

Earlier Phase-2 reporting work (`M3`–`M7`, `#12`–`#17`: receivables, karigar,
audit, operational reports) shipped as **legacy controllers**, explicitly deferred
from spine migration (see §2/§7).

**Test totals at handoff:** Reporting suite **222 passed (961 assertions)**; full
suite **591 passed, 6 skipped, 84 pre-existing failures unchanged** (RBAC / POS /
mobile-pricing baseline — held constant across every phase increment). Phase 3+4
report tests alone: **51 tests** (39 Phase 3 + 12 Phase 4).

---

## Section 2 — Report Family Inventory

| Family | Reports | Classification |
|--------|---------|----------------|
| **Compliance** | gst, gstr1, gstr3b, cn-register | **Fully migrated** (spine, rigid/Fixed) |
| **Accounting** | day-book, metal-ledger, inventory-valuation, cash-flow, daily-closing, payment-reconciliation, daily-summary, metal-liability | **Fully migrated** (spine) |
| **Owner** | pnl, gold-balances | **Fully migrated** (spine, CONFIDENTIAL) |
| **Sales/Operational** | sales-register | **Partially migrated** (dataset on spine; screen route still legacy `SalesRegisterController`) |
| **Receivables** | dues-aging, emi, scheme-liability | **Legacy** (`ReceivablesReportController`) |
| **Audit** | operator-performance, suspicious-activity | **Legacy** (`AuditReportController`) |
| **Operational (Karigar)** | karigar-settlement, shrinkage | **Legacy** (`KarigarReportController`) |
| **Operational (Inventory)** | dead-stock, purchase-efficiency | **Legacy** (`ReconciliationReportController`) |
| **Operational (misc)** | metal-exchange, repairs, reference-prices | **Legacy** (dedicated controllers) |
| **Dhiran** | dhiran.reports.* (profitability) | **Legacy / separate family** (`DhiranController`, `dhiran.reports` gate) |
| **Export Center** | spine `ExportController` (panel + export) + `ExportDownloadController` | **Spine** (the modern export surface; the legacy `CsvReportExporter` is the non-spine path — see §4) |
| **Non-report surfaces under `report/`** | audit-log viewer (closure), retailer dashboards (occasions/sellers/stock-aging), whatsapp catalog | **Out of program scope** (not spine reports) |

---

## Section 3 — Spine Coverage Audit (exact counts)

- **Spine-served report screens: 14** — 13 `report.*` GET routes on `ReportScreenController@show` (cash, closing, cn-register, daily, day-book, gold, gst, gstr1, gstr3b, inventory-valuation, metal-liability, payment-reconciliation, pnl) **+ metal-ledger** via `ledger.index`.
- **Registered spine datasets: 15** (the 14 above + `sales-register`).
- **Legacy report screens (genuine report families, not spine): 12** — dues-aging, emi, scheme-liability, operator-performance, suspicious-activity, karigar-settlement, shrinkage, dead-stock, purchase-efficiency, metal-exchange, repairs, reference-prices.
- **Out-of-scope `report/` routes: 6** — audit (log viewer), occasions, sellers, stock-aging (retailer dashboards), whatsapp, whatsapp/collection-link.
- **Legacy `/export` CSV routes: 10** (all non-spine — see §4).
- **Duplicate / mixed entry points: 2**
  - `sales-register` — spine **dataset** registered, but screen route still `SalesRegisterController@index` (**MIXED**).
  - `inventory-valuation` — spine **screen**, but `report.inventory-valuation.csv` still routes to legacy `ReconciliationReportController@inventoryValuationCsv` (**MIXED**).

---

## Section 4 — Export Audit Coverage

**Audited (writes `report_exports`, frozen §16):** the spine export path only —
`ExportController@export` → `ExportPipeline::run` → `ExportAuditService::recordSync`
(sync) / `recordQueued` (queued). **All 15 spine datasets** export through it.

**Bypasses `report_exports` (no audit):** the **10 legacy `report.*.csv` routes**,
all served by `App\Reporting\Export\CsvReportExporter` (0 `report_exports` writes):

| Route | Controller |
|-------|------------|
| report.dead-stock.csv, report.purchase-efficiency.csv, report.inventory-valuation.csv | `Reporting\ReconciliationReportController` |
| report.dues-aging.csv, report.emi.csv, report.scheme-liability.csv | `Reporting\ReceivablesReportController` |
| report.karigar-settlement.csv, report.shrinkage.csv | `Reporting\KarigarReportController` |
| report.operator-performance.csv, report.suspicious-activity.csv | `Reporting\AuditReportController` |

`CsvReportExporter` is also referenced by `Reporting\TaxReportController` (legacy tax CSV path).

**Export gap summary:** 15 spine datasets audited; 10 legacy CSV routes unaudited;
2 Owner + all Accounting/Compliance reports fully audited.

---

## Section 5 — Orphaned Components (evidence; nothing deleted)

| Component | Evidence | Status |
|-----------|----------|--------|
| `PnlController` | `grep PnlController routes/` → no match (repointed `913bda4`) | **Orphaned controller** |
| `DailyReportController` | not routed (repointed `66b45d9`) | **Orphaned controller** |
| `ReportController@gold` | `report.gold` repointed `6358049`; class still used for `reports` hub | **Orphaned method** |
| `ReconciliationReportController@paymentReconciliation`, `@metalLiability` | routes repointed in P3; class still serves dead-stock / purchase-efficiency / inventory CSV | **Orphaned methods** |
| `ReceivablesReportController@metalLiabilityCsv` | CSV route retired in P3 | **Orphaned method** |
| `resources/views/report_pnl.blade.php` | only renderer was `PnlController` (orphaned) | **Orphaned view** |
| `resources/views/reports/gold.blade.php` | only renderer was `ReportController@gold` (orphaned) | **Orphaned view** |
| `resources/views/report_daily.blade.php` | only renderer was `DailyReportController` (orphaned) | **Orphaned view** |

No dead spine routes. All orphans are pre-spine controllers/views left in place
(safe, zero runtime impact) for a deliberate cleanup pass — **not deleted here**.

---

## Section 6 — Validator Inventory (`reports:validate`)

| Family | Checks | Reconciles |
|--------|--------|-----------|
| GST | GST-1..7 | summary, GSTR-1/3B, CN register |
| Inventory | VAL-1..3, DS-1/2, PUR-1 | valuation, dead-stock, purchase efficiency |
| Cash | CASH-1..3 | cash flow |
| Closing | CLOSE-1..4 | daily closing (cross-phase) |
| Payment | PAY-1..4 (+ PAY-5..7 recon-vs-GST) | payment reconciliation |
| Daily | DAILY-1..4 | daily sales summary |
| Metal Liability | METAL-1..4 | customer-advance vs on-hand (vault:reconcile source) |
| **P&L** | **PNL-1..4** | revenue / COGS / margin / cost-unknown |
| **Gold Balances** | **GOLD-1..3** | raw SUM / vault:reconcile source / rollups |
| Receivables | DUE-1/2, EMI-1/2, SCH-1/2 | dues / EMI / scheme |
| Karigar | KAR-1/2, SHR-1 | settlement / shrinkage |
| Audit | OP-1, SUS-1 | operator / suspicious |

Plus the standalone commands `vault:reconcile` (authoritative vault balance, reused
by Gold Balances + Metal Liability) and `returns:validate`. `reports:validate`
exits **0** across all families on a reconciled shop.

---

## Section 7 — Modernization Debt (classified)

| # | Debt | Detail | Severity | Justification |
|---|------|--------|:--------:|---------------|
| 1 | Legacy CSV export audit gap | 10 `report.*.csv` routes via `CsvReportExporter` bypass `report_exports` | **MEDIUM** | Traceability gap for operational/receivables/audit exports; all still gated by `reports.view`, no data-integrity risk |
| 2 | Non-spine report families | 12 legacy report screens (receivables, audit, karigar, operational) | **MEDIUM** | Functional and correct; lack spine uniformity (profiles, PDF/Excel parity, audit). Not trust-critical |
| 3 | Mixed routes | `sales-register` (route legacy / dataset spine), `inventory-valuation` (CSV legacy / screen spine) | **LOW** | Cosmetic inconsistency; both work; spine dataset already exists for sales-register |
| 4 | Orphaned controllers/views | `PnlController`, `DailyReportController`, `ReportController@gold`, 2 `ReconciliationReportController` methods, 3 Blade views | **LOW** | Dead code, zero runtime impact; safe to delete in a cleanup pass |
| 5 | Tax legacy CSV | `TaxReportController` still uses `CsvReportExporter` alongside the spine tax datasets | **LOW** | The spine GST/GSTR datasets are the source of truth; legacy CSV is a residual path |

No HIGH-severity reporting debt. Nothing here blocks pilot or compromises the
trust chain of any migrated report.

---

## Section 8 — Phase 5 Assessment (recommendation only)

1. **Is a Phase 5 required?** **Not to complete the frozen program.** The
   Compliance + Accounting + Owner spine-migration contract is 100% delivered,
   reconciled, audited and tested. A Phase 5 would be **optional modernization
   cleanup**, not new trust-critical work.
2. **If undertaken, what belongs in Phase 5:**
   - Migrate the 12 legacy report families (receivables, audit, karigar,
     operational) to the spine — datasets wrapping their existing canonical
     services; this also closes the CSV audit gap by routing exports through
     `ExportPipeline`.
   - Retire `CsvReportExporter`; resolve the 2 mixed routes (`sales-register`,
     `inventory-valuation`).
   - Delete the orphaned controllers and views (§5).
3. **What must NOT be in Phase 5:** new report types, BI/analytics, a report
   builder, any architecture redesign (the spine is frozen), and the deferred
   margin-redacted CA-shareable P&L (an explicit out-of-contract enhancement).
   Retailer-dashboard surfaces (occasions/sellers/stock-aging) and the WhatsApp
   catalog are **not** reports and stay out.
4. **What should be deferred:** all of Phase 5 — it is low/medium modernization
   debt behind higher roadmap priorities (§9).

---

## Section 9 — Roadmap Alignment

| Priority | Relation to reporting | Reporting a blocker? |
|----------|----------------------|:--------------------:|
| 1. Mobile parity hardening | Independent | No |
| 2. UI/UX consistency pass | Touches report *screens* cosmetically | No |
| 3. API semantic audit | Independent (mobile/API endpoints) | No |
| 4. Permission/role audit | RBAC hardening (issues #8–10 still open) — higher leverage | No |
| 5. Operator workflow simplification | Independent | No |
| 6. Pilot usability testing | Needs trustworthy reports — **already delivered** | No (satisfied) |
| 7. Reporting polish | The optional Phase 5 cleanup | — |

**Recommendation: FREEZE reporting after Phase 4.** The trust-critical reporting
surface (every owner/CA/compliance figure) is complete, reconciled, and audited —
exactly what pilot usability needs. The remaining reporting debt is LOW/MEDIUM and
non-blocking. Higher-leverage roadmap items (permission/role audit, mobile parity,
pilot usability, operator workflow) should proceed first; resume reporting as an
optional Phase 5 cleanup when convenient.

---

## Final Verdict

- **Reporting completion (frozen Compliance/Accounting/Owner scope): 100%.**
- **Whole report-surface modernization: ~54%** — 14 spine-routed screens (+1 spine
  dataset, route-mixed) vs 12 genuine legacy report routes (+2 mixed).
- **Migrated report count: 15 datasets** (14 fully spine-routed; `sales-register`
  dataset-migrated, route-mixed).
- **Remaining legacy report count: 12** (+2 mixed routes).
- **Modernization debt count: 5 categories** (10 unaudited CSV routes; 12 legacy
  screens; 2 mixed routes; ~8 orphaned components; 1 residual tax CSV path) — all
  LOW/MEDIUM, none blocking.

> # REPORTING PROGRAM COMPLETE
>
> The frozen report-export program (Phases 0–4: Compliance, Accounting, Owner) is
> **complete** — every targeted report is on the spine, reconciles by construction
> and via `reports:validate`, exports to PDF/Excel/CSV with `report_exports`
> auditing, is permission-gated, tenant-isolated, and tested, with the 84-failure
> baseline unchanged throughout.
>
> A **Phase 5 is OPTIONAL** (modernization cleanup of the remaining legacy report
> families, the CSV audit gap, mixed routes, and orphaned code) and is
> **recommended to be DEFERRED** behind higher roadmap priorities. It is not
> required to consider the trust-critical reporting program delivered.

---

*Separate, per standing instruction: the `DashboardMetricsService` open-repairs fix
is unrelated to reporting and is committed as its own `fix(dashboard)` change —
never mixed into reporting work.*
