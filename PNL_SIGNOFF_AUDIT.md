# Profit & Loss — Sign-off Audit (read-only)

*Date: 2026-06-06 · Commit under audit: `913bda4` · No code modified.*

Evidence-backed verification of the Phase 4 P&L report before Gold Balances begins.

| # | Audit item | Result |
|---|------------|:------:|
| 1 | report.pnl free of any legacy export path | **PASS** |
| 2 | Exports flow Dataset → Definition → ExportPipeline → Audit | **PASS** |
| 3 | report_exports written for PDF, Excel, CSV | **PASS** |
| 4 | CONFIDENTIAL watermark behavior correct | **PASS** |
| 5 | CA Standard cannot expose cost/margin | **PASS** (design note) |
| 6 | reports.export_sensitive gating cannot be bypassed | **PASS** |
| 7 | Tenant isolation on screen + export | **PASS** |
| 8 | PNL-1/2/3/4 validator matches report logic | **PASS** |
| 9 | costUnknownLines visible on screen + exports | **PASS** |
| 10 | No orphaned/legacy P&L screen user-reachable | **PASS** |

---

### 1 — No legacy export path · PASS
[web.php:430](routes/web.php#L430): `report.pnl` → `ReportScreenController@show` `->defaults('report','pnl')`. No `report.pnl.csv`, no `PnlController` export route exists in `routes/web.php`. The legacy P&L had no CSV route to begin with; the screen is now spine-served.

### 2 — Export flow through the spine · PASS
`pnl` is a registered dataset (`ReportRegistry`). The only export entry is `ExportController@export` (POST `/reports/{report}/export`), which runs `pipeline->run($datasetRequest, $meta)` ([ExportController.php:118](app/Http/Controllers/Reporting/ExportController.php#L118)) then `audit->recordSync(...)` ([:119](app/Http/Controllers/Reporting/ExportController.php#L119)). Build consumes `ProfitLossDataset` → `ReportDefinition` → `ExportPipeline` → `ExportAuditService`. No bypass path.

### 3 — report_exports for PDF/Excel/CSV · PASS
`ExportSizeRouter::mode(pnl, 12 rows)` returns **sync** for `pdf`, `excel`, and `csv` (estimateRowCount = 12). Sync mode calls `recordSync` → `ReportExport::create([...])` ([ExportAuditService.php:89](app/Services/Reporting/ExportAuditService.php#L89)) — one `report_exports` row per export, all three formats. (The queued branch also audits via `recordQueued`, so larger exports remain covered.) `ProfitLossReportTest::test_export_writes_audit_row` asserts the row for CSV (`report_key=pnl`); all three formats share the identical `recordSync` path.

### 4 — CONFIDENTIAL watermark · PASS
`WatermarkPolicy::for($pnlDef, …)` returns `'CONFIDENTIAL'` for **both** `(Summary, sensitive=false)` and `(Detailed, sensitive=true)`. Driven by `watermarkBaseline: 'CONFIDENTIAL'` on the definition — the whole document is watermarked regardless of profile or sensitive opt-in, and the same `$watermark` is stamped into provenance on screen and export.

### 5 — CA Standard cannot expose cost/margin · PASS (with design note)
`pnl` profiles = `summary, detailed, ca`; `supportsProfile(CaStandard)` = **no**. CA Standard is not selectable, so **no CaStandard rendering or export of P&L can exist** — it cannot expose anything. `ReportScreenController::resolveProfile`/`ExportController` fall back to Detailed for any unsupported request.

> **Design note (HIGH-RISK acknowledgement, not a defect):** within the profiles P&L *does* offer, `amount` (incl. COGS) and `percent` (margin) are **Mandatory** columns and are always shown — correct for a P&L (the statement is meaningless without them). Confidentiality is enforced by the **CONFIDENTIAL watermark + the Owner-class `reports.view` gate**, not by per-column hiding. If product ever needs a CA-shareable P&L that *redacts* margin, that would require reclassifying those columns as sensitive — a future enhancement, explicitly out of the current contract. This is the one item that warrants product sign-off; it is **not** a blocker.

### 6 — reports.export_sensitive cannot be bypassed · PASS
`pnl` declares **zero sensitive columns** (all 4 are Mandatory). There is no sensitive surface for P&L, so nothing gated by `reports.export_sensitive` and nothing to bypass. The gating engine itself remains intact and unit-tested (`ColumnPolicy`: sensitive columns require `includeSensitive && user->hasPermission(sensitive) && !profileForbidsSensitive`; `ColumnPolicyTest`). P&L's confidentiality model is watermark + view-gate (see item 5), by design.

### 7 — Tenant isolation (screen + export) · PASS
Both paths derive the shop from the authenticated user: `ReportScreenController` `shopId: (int) $user->shop_id` ([:69](app/Http/Controllers/Reporting/ReportScreenController.php#L69)) and `ExportController` `shopId: (int) $user->shop_id` ([:90](app/Http/Controllers/Reporting/ExportController.php#L90)); the queued job carries `shop_id` ([:140](app/Http/Controllers/Reporting/ExportController.php#L140)). `ProfitReportingService` scopes **every** query by shop (`where('shop_id', $shopId)` ×2, `where('invoices.shop_id', $shopId)`). `ProfitLossReportTest::test_tenant_isolation_and_query_bounded` proves shop A (₹230,000) never sees shop B's ₹999,999 sale.

### 8 — Validator matches report logic · PASS
Independent raw-table recomputes in `validatePnl` mirror `ProfitReportingService::summary()` exactly:
- **PNL-1 revenue:** `Σ salesIn(subtotal) − Σ discount − Σ credit_notes.subtotal(issued in period)` == service `revenue` formula.
- **PNL-2 COGS:** identical scope — `invoices.shop_id`, `status=FINALIZED`, `COALESCE(finalized_at,created_at) BETWEEN start,end`, `Σ COALESCE(items.cost_price,0)` ([validator :688-691](app/Console/Commands/ValidateReportTotals.php#L688) == [service :65-67](app/Reporting/ProfitReportingService.php#L65)).
- **PNL-3:** `grossProfit == revenue − COGS`.
- **PNL-4:** `whereNull(items.cost_price)` count over the **same** finalized/in-period scope ([validator :712-715](app/Console/Commands/ValidateReportTotals.php#L712)) == service `SUM(CASE WHEN cost_price IS NULL …)` ([:73](app/Reporting/ProfitReportingService.php#L73)). `reports:validate` exits 0 (`test_reports_validate_pnl_path_passes`).

### 9 — costUnknownLines visible on screen + exports · PASS
`ProfitLossDataset::build()` unconditionally emits the `data_quality` section with the row **"Lines Missing Cost"** on the Mandatory `count` column — so it is rendered on the screen and in every export format (PDF/Excel/CSV) by the generic renderers. It is additionally surfaced in the validator output (the PNL-4 check name embeds the count: `… surfaced (1) …`). `ProfitLossReportTest` asserts the dataset value (`Lines Missing Cost = 1`, `Sold Lines = 3`).

### 10 — No orphaned/legacy P&L screen reachable · PASS
`grep PnlController routes/` → **no matches**: `PnlController` is no longer routed anywhere (orphaned dead code, like `DailyReportController` post-Phase-3). The `report_pnl` Blade is unreferenced by any route. The only user-reachable P&L surface is the spine screen at `report.pnl`.

---

## Blockers
**None.**

## High-risk issues
**One, for acknowledgement only (item 5):** P&L intentionally shows cost (COGS) and margin in every offered profile, protected by the CONFIDENTIAL watermark + Owner-class `reports.view` gate rather than per-column redaction. This is the correct model for a P&L statement and matches the documented contract, but if a future requirement calls for a margin-redacted CA-facing P&L, those columns would need to become sensitive. Recommend explicit product sign-off; it does not block Gold Balances.

## Final verdict

# READY FOR GOLD BALANCES

All 10 items PASS, no blockers, one design decision flagged for acknowledgement.
P&L is spine-only, audited on every format, tenant-isolated on both screen and
export, watermarked CONFIDENTIAL, and its validator logic is line-for-line
consistent with the canonical service. The legacy `PnlController` is orphaned and
unreachable.

*(Separate, per standing instruction: the `DashboardMetricsService` open-repairs
fix is unrelated to reporting and remains an isolated working-tree change to be
committed only as its own `fix(dashboard)` — never mixed into reporting work.)*
