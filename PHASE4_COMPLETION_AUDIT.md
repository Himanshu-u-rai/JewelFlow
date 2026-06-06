# Phase 4 — Owner Reports: Completion Audit

*Date: 2026-06-06 · Branch: `feature/report-export-architecture`*

Phase 4 delivered the **Owner-class** report set onto the frozen reporting spine
(`Dataset → ReportDefinition → Screen → PDF → Excel → CSV → Audit → Permissions`).
Both reports **wrap a canonical service verbatim** and reconcile **by construction**
to already-trusted sources — no new calculation engines, no architecture changes,
no modernization debt touched.

This audit certifies both Owner reports are implemented, reconciled, exported,
audited, permission-tested, and performance-tested, with no regressions.

---

## 1. Reports delivered (2 of 2)

| # | Report | Key | Source of Truth (wrapped) | Class | Commit |
|---|--------|-----|---------------------------|-------|--------|
| 1 | Profit & Loss | `pnl` | `ProfitReportingService::summary()` | Owner | `913bda4` |
| 2 | Gold Balances | `gold-balances` | `BullionVaultService::vaultBalances()` | Owner | `6358049` |

Pre-start readiness was certified in `PHASE4_PRESTART_AUDIT.md` (GO); P&L was
separately signed off in `PNL_SIGNOFF_AUDIT.md` (READY FOR GOLD BALANCES, item-5
margin-exposure accepted by product).

---

## 2. Implemented ✓

Both register in `ReportingServiceProvider::boot()` guarded by `if (! has(KEY))`.
The live registry reports **15 keys** — the two new Owner reports plus the 13
Phase 1–3 datasets:

```
… inventory-valuation, cash-flow, daily-closing, payment-reconciliation,
   daily-summary, metal-liability,            ← Phase 3
   pnl, gold-balances                          ← Phase 4 (2)
```

Both served by the generic `ReportScreenController@show` (`report.pnl`,
`report.gold`), preserving the legacy URL / name / `reports.view` permission.
Both are **Owner class** and watermarked **CONFIDENTIAL**.

---

## 3. Reconciled ✓ (by construction + validator)

| Report | Reconciliation proofs (`reports:validate`) |
|--------|--------------------------------------------|
| Profit & Loss | `PNL-1` revenue == Sales Register subtotal − discounts − returns · `PNL-2` COGS == sold `items.cost_price` · `PNL-3` margin == revenue − COGS · `PNL-4` cost-unknown lines surfaced (count) == independent recompute |
| Gold Balances | `GOLD-1` report total == `SUM(metal_lots.fine_weight_remaining)` · `GOLD-2` report total == `vault:reconcile` source total · `GOLD-3` per-metal rollups == grand total |

- **P&L** wraps `ProfitReportingService::summary()`; revenue ties to the Sales
  Register scope (`Invoice::salesIn`), COGS to the Inventory Valuation cost basis
  (`items.cost_price`). Validator logic is line-for-line consistent with the
  service (verified in `PNL_SIGNOFF_AUDIT.md`).
- **Gold Balances** wraps `BullionVaultService::vaultBalances()` — the **same**
  balance engine `vault:reconcile` and the legacy report use. The grand total is
  `SUM(metal_lots.fine_weight_remaining)`, the authoritative figure
  `vault:reconcile` certifies. No second balance engine was created.

`reports:validate` exits **0** with all PNL-* and GOLD-* lines printed.

---

## 4. Exported & Audited ✓

Both declare formats `[Pdf, Excel, Csv, Screen]` and export only through the spine
`ExportController@export` → `ExportPipeline::run` → `ExportAuditService::recordSync`
(`report_exports` row, frozen §16). `ExportSizeRouter` routes all three formats
**sync** at these row counts, so every PDF/Excel/CSV export writes one audit row.

- `ProfitLossReportTest::test_export_writes_audit_row` asserts `report_exports`
  row with `report_key=pnl`.
- `GoldBalancesReportTest::test_export_writes_audit_row` asserts `report_exports`
  row with `report_key=gold-balances`.
- `test_all_file_formats_render` proves PDF/Excel/CSV each produce non-zero output.

No legacy export path remains for either report (`PnlController` and
`ReportController@gold` are now orphaned/unreachable; no `*.csv` legacy routes).

---

## 5. Permission-tested ✓

Both gated by `reports.view`; `test_screen_requires_reports_view_permission`
asserts **403 without / 200 with** for each. Owner-class confidentiality is
enforced by the gate plus the CONFIDENTIAL watermark (verified for both
definitions). P&L's cost/margin exposure in all profiles is the accepted item-5
design decision; Gold Balances has no sensitive columns.

---

## 6. Performance-tested ✓ (tenant isolation + bounded queries)

Both derive the shop from the authenticated user on screen and export paths, and
their services scope every query by shop. Each report's
`test_tenant_isolation_and_query_bounded` proves shop A never sees shop B's data
and the build stays within a bounded query budget:

| Report | Isolation assertion | Query bound |
|--------|---------------------|-------------|
| Profit & Loss | A = ₹230,000 revenue, never B's ₹999,999 sale | ≤ 10 |
| Gold Balances | A = 200 g on-hand, never B's 999,999 g lot | ≤ 8 |

---

## 7. Test coverage

| Report | Test file | Tests | Assertions |
|--------|-----------|------:|-----------:|
| Profit & Loss | `ProfitLossReportTest` | 6 | 24 |
| Gold Balances | `GoldBalancesReportTest` | 6 | 21 |
| **Phase 4 report tests** | | **12** | **45** |

Each verifies: reconciliation (statement/rollup + raw tie-outs), validator path,
export parity (PDF/Excel/CSV), export-audit row, permission gate, tenant
isolation, and bounded queries.

---

## 8. Validator coverage (Phase 4 additions)

`reports:validate` now asserts, across all prior families plus:

```
PNL-1..4    profit & loss (revenue / COGS / margin / cost-unknown)
GOLD-1..3   gold balances (raw SUM / vault:reconcile source / rollups)
```

Gold Balances additionally reconciles through the existing `vault:reconcile`
command (its authoritative source) — reused, not duplicated.

---

## 9. Regression status

- **Reporting suite**: **222 passed (961 assertions)**.
- **Full suite**: **591 passed**, 6 skipped, **84 pre-existing failures unchanged**
  (RBAC / POS / mobile-pricing baseline — not regressions). Each Phase 4 increment
  held the baseline at 84.
- `php artisan view:clear` run after each report.

---

## 10. Verdict

| Requirement | Status |
|-------------|:------:|
| Both Owner reports implemented | ✓ 2/2 |
| Both reconciled | ✓ PNL-*/GOLD-* + by construction |
| Both exported (PDF/Excel/CSV) | ✓ |
| Both audited (`report_exports`) | ✓ via ExportPipeline |
| Both permission-tested | ✓ reports.view 403/200 |
| Both performance-tested | ✓ tenant isolation + bounded queries |
| No regressions | ✓ baseline 84 unchanged |

**Phase 4 (Owner) is COMPLETE.** All Owner-class reports (Profit & Loss, Gold
Balances) are on the spine, reconciled, audited, gated, and tested. The frozen
report-export modernization for the Owner / Accounting / Compliance report set is
delivered.

> **Modernization debt** identified in `PHASE4_PRESTART_AUDIT.md` (legacy `*.csv`
> routes bypassing `report_exports`, the mixed `sales-register` / `inventory-valuation`
> routes, and orphaned controllers) was **deliberately not touched** in Phase 4 and
> remains tracked for a separate cleanup effort.
>
> **Separate, per standing instruction:** the `DashboardMetricsService` open-repairs
> fix is unrelated to reporting and remains an isolated working-tree change to be
> committed only as its own `fix(dashboard)` — never mixed into reporting work.
