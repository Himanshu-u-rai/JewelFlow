# Phase 3 — Accounting Reconciliation Matrix

> **Design only — no implementation.** Establishes the accounting *trust contract*
> before any Phase 3 report is built: every report names its canonical data
> source, its reconciliation target(s), the validation method, the existing
> validator reused, and any new validator required. Same discipline as Phases 1–2:
> **reconcile by construction** by wrapping the existing canonical service.
>
> **Date:** 2026-06-05 · **Branch:** `feature/report-export-architecture`

---

## Scope & classification note (no redesign)

Per the frozen plan §3, the strict **Phase 3 ACCOUNTING** set is: Daily Closing,
Cash Flow, Payment Reconciliation, Inventory Valuation, Daily (sales summary),
Metal Liability, and the **Metal Movement Ledger** (Addendum C §30).

**Profit & Loss** and **Gold Balances** are **OWNER**-class in the frozen matrix
(§22) and are scheduled in **Phase 4**. They are included in this matrix **only to
document their reconciliation contract early** (as requested) — this is
documentation, **not** a reclassification and **not** a commitment to implement
them in Phase 3. Each row below is tagged with its frozen class + frozen-plan phase.

Every report reuses the proven spine: **Dataset → ReportDefinition → Screen → PDF
→ Excel → CSV → Audit → Permissions** (no report-specific export path).

---

## The matrix

| Report (route) | Frozen class · phase | Source of Truth | Reconciliation Target |
|---|---|---|---|
| **Metal Movement Ledger** (`ledger.index`) | Accounting · **P3** | `metal_movements` via `LedgerController` / `LedgerService` (append-only movement ledger) | `metal_lots.fine_weight_remaining` (Σ movements per lot) ↔ `vault:reconcile` |
| **Inventory Valuation** (`report.inventory-valuation`) | Accounting · **P3** | `App\Reporting\InventoryService::inventoryValuation()` (in-stock items at cost) | `items` table (Σ `cost_price` where `status=in_stock`); bucket subtotals = grand total |
| **Cash Flow / Cash Reports** (`report.cash`) | Accounting · **P3** | `App\Reporting\LedgerService` (cash methods → `CashDayData`) over `cash_transactions` | `cash_transactions` (Σ in − Σ out = running balance) |
| **Daily Closing** (`report.closing`) | Accounting · **P3** | `ClosingController` aggregation over `cash_transactions` + finalized invoices/GST | Sales Register + GST Report + `cash_transactions` for the date |
| **Payment Reconciliation** (`report.payment-reconciliation`) | Accounting · **P3** | `App\Reporting\SalesService::paymentReconciliation()` | per-invoice billed (`invoices.total`) vs collected (`invoice_payments`); variance = billed − collected |
| **Daily (sales summary)** (`report.daily`) | Accounting · **P3** | `App\Reporting\LedgerService::metalMovementDay()` / daily sales aggregation | Sales Register / finalized `invoices` for the date |
| **Metal Liability** (`report.metal-liability`) | Accounting (Receivables sub-set §11) · **P3** | `App\Reporting\ReceivablesService::metalLiability()` | customer advance-gold owed vs on-hand (`customer_gold_*` / advances) ↔ vault on-hand |
| **Profit & Loss** (`report.pnl`) | **Owner · P4** (contract documented early) | `App\Reporting\ProfitReportingService` | revenue = Sales Register taxable (Σ finalized `invoices.subtotal`); COGS = Inventory cost basis; margin = revenue − COGS |
| **Gold Balances** (`report.gold`) | **Owner · P4** (contract documented early) | `metal_lots` (Σ `fine_weight_remaining` grouped by metal/purity) via `ReportController@gold` | `vault:reconcile` (authoritative vault balance) |

---

## Per-report contract detail

For each: **(1)** canonical data source · **(2)** reconciliation target · **(3)** validation method · **(4)** existing validator reused · **(5)** new validator required.

### 1. Metal Movement Ledger — `ledger.index` (Accounting, P3)
1. `metal_movements` (append-only) via `LedgerService` (the spine `MetalMovementLedgerDataset` wraps it; **no re-query** in the renderer).
2. Σ fine-weight in/out per lot **==** `metal_lots.fine_weight_remaining`; gram-accountability closure (purchases + recoveries = closing + issued + wastage).
3. By construction (wrap the same query `LedgerController` uses) + a balance tie-out.
4. **Reuse `ReconcileVaultBalances` (`vault:reconcile`)** as the oracle — already validates lot balances against movements.
5. Optional: `ValidateReportTotals --ledger` (assert dataset fine-weight totals == `vault:reconcile` output for the scope).

### 2. Inventory Valuation — `report.inventory-valuation` (Accounting, P3)
1. `InventoryService::inventoryValuation()` (in-stock items valued at `cost_price`).
2. Σ bucket cost values **==** grand total **==** `items` Σ `cost_price` (`status=in_stock`) for the as-of date/metal filter.
3. By construction (wrap `InventoryService`).
4. **Reuse** the `InventoryService` coverage already pinned by `DeadStockReportTest` (same service family).
5. New: `ValidateReportTotals --inventory` (bucket subtotals sum to total; cost-basis tie to `items`).

### 3. Cash Flow / Cash Reports — `report.cash` (Accounting, P3)
1. `LedgerService` cash methods (`CashDayData`) over `cash_transactions`.
2. Running balance ties out: opening + Σ in − Σ out = closing **==** `cash_transactions` aggregate for the period.
3. By construction (wrap `LedgerService`).
4. Existing: the plan's daily cash checks (manual). No dedicated command yet.
5. New: `ValidateReportTotals --cash` (running balance reconciles to `cash_transactions`).

### 4. Daily Closing — `report.closing` (Accounting, P3)
1. `ClosingController` aggregation: `cash_transactions` for the date + finalized sales + GST.
2. Closing sales+GST for a date **==** Sales Register (Phase 1) + GST Report (Phase 2) for that date; cash totals **==** `cash_transactions`.
3. **Cross-phase reconciliation** (chain consistency: closing ↔ sales ↔ GST ↔ cash).
4. **Reuse `ValidateReportTotals`** (extend with the closing cross-check) + the Phase 1 Sales tie-out + Phase 2 GST tie-out.
5. New: `ValidateReportTotals --closing <date>`.

### 5. Payment Reconciliation — `report.payment-reconciliation` (Accounting, P3)
1. `SalesService::paymentReconciliation()`.
2. Per invoice: billed (`invoices.total`) − collected (Σ `invoice_payments.amount`) = variance; Σ collected **==** `invoice_payments` for the period.
3. By construction (wrap `SalesService`).
4. Existing: none specific.
5. New: `ValidateReportTotals --payment-recon` (Σ variance + collected tie to source tables).

### 6. Daily (sales summary) — `report.daily` (Accounting, P3)
1. `LedgerService::metalMovementDay()` / daily sales aggregation.
2. Daily sales count + GST **==** Sales Register / finalized `invoices` for that date.
3. **Cross-phase** (ties to the Phase 1 Sales Register).
4. **Reuse** the Phase 1 Sales Register tie-out.
5. New: `ValidateReportTotals --daily <date>` (optional; subsumed by the Sales tie-out).

### 7. Metal Liability — `report.metal-liability` (Accounting/Receivables, P3)
1. `ReceivablesService::metalLiability()`.
2. Customer advance-gold owed **==** advance ledger; on-hand **==** vault on-hand.
3. By construction (wrap `ReceivablesService`).
4. Existing: `vault:reconcile` for the on-hand side.
5. New: `ValidateReportTotals --metal-liability` (owed vs on-hand tie).

### 8. Profit & Loss — `report.pnl` (Owner, P4 — contract only)
1. `ProfitReportingService`.
2. Revenue **==** Sales Register taxable (Σ finalized `invoices.subtotal`); COGS **==** Inventory cost basis; margin = revenue − COGS.
3. By construction (wrap `ProfitReportingService`); chain-reconcile to Sales Register (P1) + Inventory Valuation (P3).
4. **Reuse** Sales Register tie-out + Inventory validator.
5. New: `ValidateReportTotals --pnl` (revenue ties to sales). *(Built in Phase 4.)*

### 9. Gold Balances — `report.gold` (Owner, P4 — contract only)
1. `metal_lots` (Σ `fine_weight_remaining` by metal/purity).
2. **==** `vault:reconcile` authoritative balance.
3. By construction (wrap the `MetalLot` aggregate).
4. **Reuse `ReconcileVaultBalances` (`vault:reconcile`)**.
5. New: none (reuse `vault:reconcile`). *(Built in Phase 4.)*

---

## Existing validators inventory (confirmed present)

| Validator (command) | File | Reused for |
|---|---|---|
| `reports:validate` | `app/Console/Commands/ValidateReportTotals.php` | extend with `--ledger/--inventory/--cash/--closing/--payment-recon/--metal-liability` checks |
| `vault:reconcile` | `app/Console/Commands/ReconcileVaultBalances.php` | Metal Movement Ledger, Gold Balances, Metal Liability on-hand |
| `karigar:reconcile` | `app/Console/Commands/ReconcileKarigarBalances.php` | (gold-with-karigar cross-check, where relevant) |
| `returns:validate` | `app/Console/Commands/ValidateReturnsIntegrity.php` | credit-note integrity feeding cash/closing |

**New validators** are extensions of `ValidateReportTotals` (one flag per report), not new architecture — consistent with Phase 2.

---

## Phase 3 execution sequence (frozen plan order)

1. **Cleanup Task #1 first** — disposition the 3 residual legacy CSV endpoints per `PHASE2_RESIDUAL_CSV_RETIREMENT_AUDIT.md` (rewrite `TaxExportGoldenTest` to the spine format → consumer migration note → verify equivalent spine exports → verify export-audit coverage → retire). No report retired before those four steps pass.
2. Then implement the **Accounting** reports in reconciliation-dependency order: Metal Movement Ledger + Inventory Valuation + Cash Flow → Daily Closing (depends on Sales + GST + Cash) → Payment Reconciliation → Daily summary → Metal Liability.
3. Each report: dataset (wrap canonical service) → ReportDefinition → register → screen via generic `ReportScreenController` → exports (PDF/Excel/CSV) → audit → permissions → **reconciliation + parity + permission + performance tests** → no regressions.
4. Owner-class P&L + Gold Balances remain **Phase 4** (their contract is documented above for early trust).

---

*Design only. No report implemented, no validator written, no code changed.*
