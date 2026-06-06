# Phase 4 — Pre-Start Readiness Audit (Owner-class reporting)

*Date: 2026-06-06 · Branch: `feature/report-export-architecture` · Read-only diagnosis. No code changes, no migrations, no report implementation.*

Objective: verify Owner-class reporting (Profit & Loss, Gold Balances) can begin
safely, with no hidden dependencies or legacy paths that would compromise trust.
Every finding is evidence-backed (route / controller / service / validator / export).

---

## Section 1 — Owner Report Readiness

### Profit & Loss — `report.pnl`

| Aspect | Evidence | State |
|--------|----------|-------|
| Route | [web.php:428](routes/web.php#L428) `report.pnl` → `PnlController@index`, `can:reports.view` | legacy |
| Controller | `App\Http\Controllers\PnlController@index` — renders `report_pnl` view | legacy |
| Source service | `App\Reporting\ProfitReportingService::summary()` → `ProfitReportData` ([ProfitReportingService.php:33](app/Reporting/ProfitReportingService.php#L33)) | **exists** |
| Permissions | `reports.view` (Owner intent — currently same gate as other reports) | present |
| Export path | **none** — view-only; no PDF/Excel/CSV route | missing |
| Spine dataset | **none** — not registered in `ReportingServiceProvider` (13 keys, no `pnl`) | missing |
| Tests | `ProfitReportingTest` (3 tests) — service math only, not a spine dataset | partial |

**Classification: PARTIAL.** The canonical service exists and is unit-tested;
the report is not yet on the spine and has no export/audit path. Not blocked.

### Gold Balances — `report.gold`

| Aspect | Evidence | State |
|--------|----------|-------|
| Route | [web.php:420](routes/web.php#L420) `report.gold` → `ReportController@gold`, `can:reports.view` | legacy |
| Controller | `App\Http\Controllers\ReportController@gold` — `SUM(fine_weight_remaining)` grouped by `metal_type, purity`; renders `reports.gold` | legacy |
| Source | `metal_lots` aggregate (no dedicated service — inline in the controller) | **exists (inline)** |
| Permissions | `reports.view` | present |
| Export path | **none** — view-only | missing |
| Spine dataset | **none** — not registered (no `gold`/`gold-balances` key) | missing |
| Tests | none dedicated | missing |

**Classification: PARTIAL.** The aggregate exists (inline in the controller) and
matches the vault balance source; not yet on the spine, no export/audit, no test.
Not blocked.

---

## Section 2 — P&L Trust Chain

`ProfitReportingService::summary()` ([ProfitReportingService.php:33-90](app/Reporting/ProfitReportingService.php#L33)) computes:

```
Revenue  → Σ finalized invoices.subtotal (salesIn scope) − discount − returns(credit_notes.subtotal)
              ↳ SAME canonical scope as Sales Register / GST Report (Invoice::salesIn)
COGS     → Σ items.cost_price over sold invoice_items (finalized, in period)
              ↳ SAME cost_price basis as Inventory Valuation (VAL-3: total at cost == Σ in_stock cost_price)
Margin   → grossProfit = revenue − COGS ; marginPct = grossProfit / revenue
```

**Reconciliation feasibility — YES, by construction:**
- **vs Sales Register (P1):** revenue base = `Invoice::salesIn()->sum('subtotal')`, the identical canonical scope the Sales Register dataset and `CLOSE-1`/`DAILY-1`/`PAY-1` already tie out. ✓
- **vs Inventory Valuation (P3):** COGS draws `items.cost_price`, the same column `VAL-3` reconciles. (Scope differs — P&L = cost of *sold* items, Valuation = cost of *in-stock* items — but the cost basis and column are shared.) ✓
- **vs Payment Reconciliation (P3):** P&L revenue is *accrual* (billed), not *collected*; it does not equal Payment Reconciliation's collected total. The honest link is `revenue (accrual)` vs `PAY collected` as a receivable bridge, **not** an equality. Treat as a documented relationship, not a tie-out.

**Missing for Phase 4:**
- No `reports:validate` P&L flag yet (matrix §8.5 marks this as *new, built in Phase 4*): a `PNL-*` revenue tie-out to the Sales Register scope, and COGS to the cost basis.
- **Dependency / data risk:** `ProfitReportData::costUnknownLines` counts sold lines with `items.cost_price IS NULL`. COGS silently excludes them, so margin overstates when cost is unrecorded. This is a **data-quality dependency**, not a code blocker — the field already surfaces it and any `PNL-*` validator should assert/report it.

---

## Section 3 — Gold Balance Trust Chain

`ReportController@gold` ([ReportController.php](app/Http/Controllers/ReportController.php)) aggregates:

```
Gold Balance   → SUM(metal_lots.fine_weight_remaining) GROUP BY metal_type, purity   (shop-scoped)
Vault Balance  → SUM(metal_lots.fine_weight_remaining) GROUP BY metal_type           (vault:reconcile, ReconcileVaultBalances.php:105)
Reconciliation → vault:reconcile (authoritative; the existing command)
```

**Reconciliation feasibility — YES, by construction:** both the report and
`vault:reconcile` read the **same** `SUM(metal_lots.fine_weight_remaining)`
source. The report groups one level finer (metal_type + purity vs metal_type
only), so report lines roll up exactly to the vault total per metal.

**Missing for Phase 4:** none required. Matrix §9.5 says *reuse `vault:reconcile`;
no new validator*. A small optional `GOLD-*` tie-out (report grand total ==
`vault:reconcile` source) is recommended only for parity with the other Phase 3
reports — not a blocker.

---

## Section 4 — Legacy Owner Surfaces

| Surface | Path | Mechanism | Classification |
|---------|------|-----------|----------------|
| P&L screen | `report.pnl` → `PnlController@index` → `report_pnl` view | legacy controller + Blade | **legacy** |
| P&L export | — | none exists | n/a |
| Gold screen | `report.gold` → `ReportController@gold` → `reports.gold` view | legacy controller + Blade | **legacy** |
| Gold export | — | none exists | n/a |

Both Owner reports are **fully legacy** (view-only, no spine, no export, no
`report_exports` audit). Migrating each to the spine replaces the controller with
a registered dataset and grants PDF/Excel/CSV + audit for free.

---

## Section 5 — Reporting Modernization Coverage Map

**On the spine** (`ReportScreenController@show`, registered dataset): `sales-register`*,
`gst`, `gstr1`, `gstr3b`, `cn-register`, `day-book`, `metal-ledger` (via `ledger.index`),
`inventory-valuation`, `cash-flow`, `daily-closing`, `payment-reconciliation`,
`daily-summary`, `metal-liability` — **13 datasets registered**.

> *`sales-register` dataset is registered, **but** the screen route
> [web.php:476](routes/web.php#L476) still points to legacy `SalesRegisterController@index` — **MIXED** (see §6).

**Not yet migrated to the spine:**

| Family | Route(s) | Controller | Classification | Frozen phase |
|--------|----------|------------|----------------|--------------|
| **Owner** | `report.pnl` | `PnlController` | Owner | **P4 (this audit)** |
| **Owner** | `report.gold` | `ReportController@gold` | Owner | **P4 (this audit)** |
| Receivables | `report.dues-aging`, `report.emi`, `report.scheme-liability` | `Reporting\ReceivablesReportController` | Receivables | P2/P3 (legacy retained) |
| Audit | `report.operator-performance`, `report.suspicious-activity` | `Reporting\AuditReportController` | Audit | P2 (legacy retained) |
| Karigar | `report.karigar-settlement`, `report.shrinkage` | `Reporting\KarigarReportController` | Operational/Karigar | P2 (legacy retained) |
| Inventory/Recv | `report.dead-stock`, `report.purchase-efficiency` | `Reporting\ReconciliationReportController` | Operational | P2 (legacy retained) |
| Sales | `report.sales-register` | `Reporting\SalesRegisterController` | Operational | P1 (dataset exists; route legacy) |
| Misc | `report.metal-exchange`, `report.repairs`, `report.reference-prices`, `report.audit` | `MetalExchangeReportController`, `RepairReportController`, `ReferencePriceHistoryController`, closure | Operational | pre-spine |
| Dhiran | `dhiran.reports.*` (`reports.profitability`) | `DhiranController` | Dhiran | separate family, `dhiran.reports` gate |
| Retailer dash | `report.occasions`, `report.sellers`, `report.stock-aging` | `RetailerDashboardController` | Operational (dashboard) | not spine reports |

These families are **out of Phase 4 scope** (Owner-only) and do not block it; they
are tracked modernization debt.

---

## Section 6 — Route & Controller Audit (counts + evidence)

- **Report screens on the spine:** **12** surfaces (`ReportScreenController@show`: 11 `report.*` + `ledger.index`).
- **Report screens on legacy controllers:** **~15** (Owner ×2, Receivables ×3, Audit ×2, Karigar ×2, Reconciliation ×2, sales-register ×1, metal-exchange/repairs/reference-prices/audit ×4 — evidence: route:list).
- **Duplicate / mixed entry points:**
  - `inventory-valuation`: screen on spine, **but** `report.inventory-valuation.csv` still routes to legacy `ReconciliationReportController@inventoryValuationCsv` ([web.php:444](routes/web.php#L444)) — **MIXED**.
  - `sales-register`: dataset registered on spine, **but** screen route still legacy `SalesRegisterController` — **MIXED**.
- **Orphaned report controllers / methods (no route after P3 repoints):**
  - `DailyReportController` — **fully orphaned** (its only route `report.daily` repointed to the spine).
  - `ReconciliationReportController@paymentReconciliation` / `@metalLiability` — **orphaned methods** (routes repointed; class still used for dead-stock/purchase-efficiency/inventory CSV).
  - `ReceivablesReportController@metalLiabilityCsv` — orphaned (route retired).

None of these block Phase 4; they are cleanup candidates.

---

## Section 7 — Export Audit Coverage

**Audited (writes `report_exports`):** only the spine export path —
`ExportController@export` (POST `/reports/{report}/export`) via `ExportAuditService`
+ `ExportPipeline` ([ExportController.php:13,45-46](app/Http/Controllers/Reporting/ExportController.php#L13)). All 13 registered spine datasets export through it.

**Bypasses `report_exports` (no audit):** every legacy `report.*.csv` route — **10
routes** — exports via `App\Reporting\Export\CsvReportExporter`, which contains
**zero** `report_exports`/`ExportAuditService` writes (grep count: 0). Used by
`TaxReportController`, `KarigarReportController`, `ReceivablesReportController`,
`AuditReportController`, `ReconciliationReportController`.

| Family | Export path | Audited? |
|--------|-------------|----------|
| All 13 spine datasets | `ExportController` → `ExportPipeline` | ✅ yes |
| Receivables / Audit / Karigar / Reconciliation CSV (10 routes) | `CsvReportExporter` | ❌ no |
| **P&L** | none today | n/a (gains audit on spine migration) |
| **Gold Balances** | none today | n/a (gains audit on spine migration) |

**Implication for Phase 4:** P&L and Gold currently have *no* export at all, so
migrating them to the spine **adds** audited export — strictly an improvement, no
regression risk.

---

## Section 8 — Validator Coverage Inventory

`reports:validate` currently asserts (confirmed present in `ValidateReportTotals.php`,
`reports:validate` exits 0):

```
GST-1..7      tax pack (summary, GSTR-1/3B, CN register)
VAL-1..3      inventory valuation
CASH-1..3     cash flow
CLOSE-1..4    daily closing (cross-phase)
PAY-1..4      payment reconciliation (raw tables)   PAY-5..7 recon-vs-GST
DAILY-1..4    daily sales summary
METAL-1..4    metal liability (incl. vault:reconcile source)
DUE / EMI / SCH / KAR / OP / SUS / SHR   (Phase 2 receivables / karigar / audit)
```
Plus `vault:reconcile` (vault balances) and `returns:validate` (returns) as separate commands.

**No `PNL-*` and no `GOLD-*` checks exist yet.**

**Recommendations (recommendations only — not implemented here):**
- **P&L → needs new validator coverage.** Add `PNL-1` revenue == Sales Register scope (`Invoice::salesIn->sum('subtotal') − discount − Σ credit_notes.subtotal`); `PNL-2` COGS == Σ `items.cost_price` of sold lines (independent recompute); `PNL-3` margin == revenue − COGS; surface `costUnknownLines` as a reported data-quality figure. (Matrix §8.5 already designates this as Phase-4 work.)
- **Gold Balances → no new validator required.** Reuse `vault:reconcile` (matrix §9.5). A `GOLD-1` tie (report grand total == `SUM(metal_lots.fine_weight_remaining)`) is optional, for parity only.

---

## Section 9 — Phase 4 Blockers

1. **Can Phase 4 begin safely?** **Yes.** Both source paths exist and reconcile by construction to already-trusted outputs (Sales Register P1, Inventory Valuation P3, vault:reconcile).
2. **Is P&L implementation blocked?** **No.** `ProfitReportingService` exists and is tested; needs a spine dataset + `PNL-*` validator. Watch the `cost_price` completeness data risk (already surfaced by `costUnknownLines`).
3. **Is Gold Balance implementation blocked?** **No.** The `metal_lots` aggregate exists and shares the `vault:reconcile` source; needs a spine dataset wrapping that aggregate. No new validator required.
4. **Any hidden dependency risk?** One **data-quality** dependency (not a code blocker): P&L COGS depends on `items.cost_price` being populated; null costs are excluded and overstate margin. No hidden *code* dependency — neither report relies on an unmigrated service.
5. **Any modernization debt to resolve first?** Not blocking, but tracked: (a) 10 legacy `report.*.csv` routes bypass `report_exports`; (b) `inventory-valuation` and `sales-register` are MIXED (spine screen/dataset + legacy route); (c) orphaned `DailyReportController` and `ReconciliationReportController` methods. These can be cleaned in a follow-up; they do not affect Owner-class trust.

---

## Final Verdict

# GO

**Evidence:** Both Owner reports have an existing canonical source that reconciles
**by construction** to already-trusted Phase 1/3 outputs — P&L revenue to the Sales
Register scope (`Invoice::salesIn`) and COGS to the Inventory Valuation cost basis
(`items.cost_price`); Gold Balances to the authoritative `vault:reconcile` source
(`SUM(metal_lots.fine_weight_remaining)`). Neither is blocked. Migration to the
spine only **adds** audited export. The single dependency is a documented
data-quality risk (`cost_price` completeness, already surfaced by
`costUnknownLines`), and the only required new validator work is a Phase-4 `PNL-*`
tie-out; Gold Balances reuses `vault:reconcile`.

Phase 4 may begin. Recommended first step: P&L on the spine (dataset wrapping
`ProfitReportingService` + `PNL-*` validator), then Gold Balances (dataset wrapping
the `metal_lots` aggregate, reusing `vault:reconcile`).

---

*Note (kept separate, as instructed): the `DashboardMetricsService` open-repairs
fix is unrelated to reporting and is **not** part of Phase 3/4. It remains an
isolated working-tree change to be committed only as its own `fix(dashboard)`
change — never mixed into reporting work.*
