# JewelFlow — Reporting Phase 2 Execution Plan

> **Builds on:** the now-locked trust foundation (commit `ac26534`) — see [REPORTING_SYSTEM_AUDIT.md](REPORTING_SYSTEM_AUDIT.md) and [REPORTING_REFACTOR_AND_EXPANSION_PLAN.md](REPORTING_REFACTOR_AND_EXPANSION_PLAN.md).
> **Date:** 2026-06-01
> **Nature:** Bounded, implementation-grade sequencing for the CA/compliance tier + high-value operational reports. No code in this document.
> **Scope guard:** CA-respectable + operationally useful, **not** an accounting ERP / Tally replacement / GST filing gateway.

---

## 0. The Locked Foundation (consume, never duplicate)

Every Phase-2 report **must** be built on these — no controller-level aggregation, no parallel sales definition:

| Primitive | Role | Location |
|---|---|---|
| `ReportPeriod` | validated/clamped period (day/month/range) | `app/Reporting/ReportPeriod.php` |
| `Invoice` `HasSalesScopes` (`salesIn`, `finalizedSale`, `accountingBetween`) | **the** canonical "a sale = finalized + accounting date" | `app/Reporting/Concerns/HasSalesScopes.php` |
| `Money` | paisa-integer aggregation | `app/Reporting/Money.php` |
| `GstReportingService` | GST net-of-CN + CGST/SGST/IGST | `app/Reporting/GstReportingService.php` |
| `ProfitReportingService` | true gross margin | `app/Reporting/ProfitReportingService.php` |
| `reports:validate` | read-only reconciliation invariants | `app/Console/Commands/ValidateReportTotals.php` |

**Tenancy rule (every service):** `Model::withoutTenant()->where('shop_id', $shopId)` + explicit scope — works in web and console (proven by the existing services).

**Accounting-date rule:** invoices → `COALESCE(finalized_at, created_at)`; credit notes → `issued_at`; cash/movements → their own event date; installment/scheme → their payment/accounting date. Never raw `created_at` for a financial figure.

---

## 1. Architectural Rules for Phase 2 (non-negotiable)

1. **Controllers orchestrate, services aggregate.** A controller resolves a `ReportPeriod`, calls one `Reporting\*Service`, passes a DTO to the view/export. No `->sum()` / `->selectRaw()` in controllers or blades.
2. **One service per domain** (final target namespace):
   - `Reporting\TaxService` — GST/GSTR/CN-register/tax-liability (absorbs + reuses `GstReportingService`)
   - `Reporting\SalesService` — sales summaries, sellers, payment reconciliation
   - `Reporting\InventoryService` — valuation, aging, dead stock, shrinkage
   - `Reporting\LedgerService` — day-book/journal, cashbook aggregation, metal ledger
   - `Reporting\ReceivablesService` — dues, EMI, scheme/metal/old-gold liability
   - `Reporting\AuditService` — operator performance, suspicious activity
3. **Existing services are not renamed** (the foundation is locked). New domain services **depend on** them (e.g. `TaxService` calls `GstReportingService::summary()`), they do not re-query the same tables.
4. **Every report returns an immutable DTO** (`app/Reporting/Data/*`) — screen and export consume the identical DTO, so they can never diverge.
5. **Every financial report gets a `reports:validate` invariant** before it ships.
6. **DTO-first, then view, then export** — build the service+DTO+test first; UI/export last.

---

## 2. Shared Primitives to Build First (Milestone 0)

These unblock the CA tier and are reused everywhere:

| Primitive | Why | Notes |
|---|---|---|
| `Reporting\Export\CsvReportExporter` | every CA report needs streamed CSV | Promote the proven `ExportController::streamCsvChunked` into `App\Reporting\Export`; header + row-mapper + query/collection. GSTN-shaped exports are subclasses/configs. |
| `Reporting\TaxService` | home for GSTR-1/3B/CN-register/liability | thin orchestrator over `GstReportingService` + invoice/CN scopes; adds B2B/B2CS classification using `invoices.buyer_gstin` + `customers.state_code` vs `shops.state_code`. |
| `Reporting\Data\*` DTOs | screen==export guarantee | one per report. |
| `reports:validate` check registry | scale to many invariants | refactor command so each report registers its checks (keeps the file maintainable as checks grow). |

**Known data facts (verified) that the CA tier relies on:**
- `invoices`: `cgst_amount/sgst_amount/igst_amount` (backfilled), `place_of_supply_state_code`, `buyer_gstin`, `gst_override`.
- `invoice_items.hsn_code` (backfilled); `shop_billing_settings.hsn_gold/hsn_silver`.
- `customers.gstin`, `customers.state_code`; `shops.state_code`.
- `credit_notes`: splits + `place_of_supply_state_code` + `buyer_gstin` + `issued_at`.
- **Open item:** the shop's own GSTIN value column needs confirmation (only `shop_billing_settings.show_gstin` flag surfaced in recon) — resolve in M1 before GSTR-1 export headers; until then GSTIN is a documented manual field.

---

## 3. Milestone Sequence (bounded, in priority order)

Each milestone is independently shippable, reversible, and ends with green tests + `reports:validate`.

### M0 — Export + Tax service scaffolding *(small, enabling)*
`CsvReportExporter`, `TaxService` skeleton, DTO conventions, `reports:validate` check-registry refactor. No new user-facing report.

### M1 — CA Tax Pack *(highest priority)*
1. **GSTR-1 sales report** — B2B vs B2CS classification, rate-wise, HSN summary, CN section. Screen + GSTN-shaped CSV.
2. **GSTR-3B support summary** — output tax / inter- vs intra-state / net liability.
3. **Tax liability summary** — month tax position (collected − reversed − ITC-when-modelled).
4. **Credit/Debit-note register** — period-filtered, original-invoice reference, `cn_type` (full-cancel vs partial-return).

All four consume `TaxService` → `GstReportingService`. **Zero new aggregation.**

### M2 — CA Ledger & Reconciliation Pack
5. **Payment reconciliation** — payments by mode vs invoice totals vs cash transactions; flags mismatches.
6. **Day-book / journal export** — chronological transaction journal (invoices, CNs, cash, payments) for the CA's books. Export-first.
7. **Inventory valuation (at cost)** — capital tied up; `Item.cost_price` + on-hand metal at cost. (Bridges CA + operational.)

### M3 — Receivables & Liability Visibility
8. **Customer dues aging** — outstanding per customer, bucketed.
9. **Pending EMI / installment visibility** — `installment_plans`/`installment_payments`, overdue surfacing.
10. **Scheme liability exposure** — `schemes`/`scheme_ledger_entries`/`scheme_redemptions`.
11. **Metal liability + old-gold liability** — gold owed to customers (advances) vs on hand; old-gold accepted.

### M4 — Jewellery-Domain Operational Reports
12. **Dead stock / inventory aging (at cost)** — replaces the broken "worst sellers."
13. **Karigar settlement report** — issued vs received grams, making charged vs paid, wastage, outstanding (surfaces `ReconcileKarigarBalances` data as a report).
14. **Purchase efficiency** — purchase rate vs market over time.
15. **Shrinkage / loss variance** — issued − received − wastage gram gaps.
16. **Operator performance** — sales/returns/discounts by user (needs `user_id` sales dimension).
17. **Suspicious-activity / audit** — surface `DetectFraud` as a report.

### M5 — Reporting Services Extraction (cleanup)
Migrate the remaining legacy report controllers (`ReportController`, `DailyReportController`, `CashReportController`, `RepairReportController`, `MetalExchangeReportController`, `RetailerReportService`, `LedgerController`, `CashBookController`) onto `Reporting\*` services. Removes the last controller-level aggregation. No behavior change — pure refactor with parity tests.

### M6 — Report Validation Expansion
Extend `reports:validate`, add reconciliation + snapshot + aggregation-consistency tests for every M1–M4 report.

### M7 — Reporting UX & Export Layer
Summaries, filter ergonomics, drill-downs, printable/CA-friendly exports, owner-friendly plain-English views. **After** trust/compliance; no charts before trust.

---

## 4. Per-Report Spec Matrix (M1–M4)

| # | Report | Service | DTO | Source (persisted) | Accounting date | Risk |
|---|---|---|---|---|---|---|
| 1 | GSTR-1 | TaxService | GstR1Data | invoices+items(hsn)+credit_notes | finalized_at / issued_at | 🔴 |
| 2 | GSTR-3B support | TaxService | GstR3bData | invoices+credit_notes splits | finalized_at | 🔴 |
| 3 | Tax liability | TaxService | (reuse GstReportData) | invoices+credit_notes | finalized_at | 🔴 |
| 4 | CN/DN register | TaxService | CnRegisterData | credit_notes+invoices | issued_at | 🔴 |
| 5 | Payment reconciliation | SalesService | PaymentReconData | invoice_payments+invoices+cash_transactions | finalized_at | 🟠 |
| 6 | Day-book | LedgerService | DayBookData | invoices+credit_notes+cash_transactions | event date | 🟠 |
| 7 | Inventory valuation | InventoryService | InventoryValuationData | items+metal_lots | snapshot (now) | 🟡 |
| 8 | Dues aging | ReceivablesService | DuesAgingData | invoices(outstanding)+payments | finalized_at | 🟠 |
| 9 | EMI visibility | ReceivablesService | EmiData | installment_plans+payments | due_date | 🟠 |
| 10 | Scheme liability | ReceivablesService | SchemeLiabilityData | schemes+scheme_ledger_entries | accounting | 🟠 |
| 11 | Metal/old-gold liability | ReceivablesService | MetalLiabilityData | customer_gold_transactions+metal_lots | event | 🟠 |
| 12 | Dead stock | InventoryService | DeadStockData | items(in_stock)+sales | created/aging | 🟡 |
| 13 | Karigar settlement | (reuse vault recon) | KarigarSettlementData | job_orders+karigar_invoices | accounting | 🟠 |
| 14 | Purchase efficiency | InventoryService | PurchaseEffData | stock_purchases+rates | event | 🟡 |
| 15 | Shrinkage variance | InventoryService | ShrinkageData | metal_movements | event | 🟠 |
| 16 | Operator performance | AuditService | OperatorPerfData | invoices+user | finalized_at | 🟡 |
| 17 | Suspicious activity | AuditService | (reuse DetectFraud) | audit_logs+heuristics | event | 🟡 |

---

## 5. Accounting-Risk Classification

- **🔴 High (tax/liability — must reconcile + test before ship):** GSTR-1, GSTR-3B, tax liability, CN/DN register.
- **🟠 Medium (financial visibility — reconcile, test):** payment recon, day-book, dues aging, EMI, scheme/metal liability, karigar settlement, shrinkage.
- **🟡 Low (operational/intelligence — parity test, no tax exposure):** inventory valuation, dead stock, purchase efficiency, operator performance, suspicious activity.

Rule: a 🔴 report does not ship without a `reports:validate` invariant **and** a fixture total test.

---

## 6. Report Validation & Rollout Strategy

For each report:
1. **Service + DTO + fixture test first** (lock the numbers).
2. **`reports:validate` invariant** for 🔴/🟠 (e.g. GSTR-1 taxable Σ == GST report taxable; GSTR-3B net == tax-liability net; payment recon: Σpayments ≤ Σinvoice totals).
3. **Snapshot test** on the exported CSV (golden file) for 🔴 exports.
4. **Parity test** for M5 refactors (old controller output == new service output on seeded data).
5. **Rollout:** ship behind the existing report nav + permissions; no deprecation of a working report until its replacement passes parity.

`reports:validate` grows from a flat method into a **check registry** (M0) so each report contributes checks without bloating one method.

---

## 7. Drift-Prevention Rules (enforced in review)

- ❌ No `Invoice::...->sum()` / `selectRaw` in any controller or blade (grep gate in review).
- ❌ No new "sales" definition — only `salesIn()`.
- ❌ No new date filter on `created_at` for a financial figure.
- ✅ New report = new `Reporting\*Service` method + DTO + test, consumed by a thin controller.
- ✅ Any GST number flows through `GstRateResolver` / persisted columns, never recomputed.

---

## 8. Accounting Boundary (preserve)

| JewelFlow OWNS | JewelFlow does NOT own |
|---|---|
| Transactional truth (invoices, CNs, payments, movements) | Full double-entry books / balance-sheet finalization |
| GST-ready reporting (GSTR-1/3B data + exports) | GSTN portal filing submission |
| Operational accounting + reconciliation support | Income-tax computation, statutory financials |
| Day-book + CA-grade CSV/JSON exports | Being the CA's accounting engine |

Day-book, trial-balance-style data, and GL are **exports for the CA's tool**, not in-app double-entry statements.

---

## 9. Constraints

Do NOT: rebuild reporting into analytics chaos, duplicate financial semantics, create parallel sales definitions, bypass reporting primitives, or prioritize charts before trust. Everything consumes canonical semantics + shared scopes + reporting services.

---

## 10. Immediate Execution Order

1. **M0** — `CsvReportExporter` + `TaxService` skeleton + `reports:validate` check registry.
2. **M1** — CA Tax Pack (GSTR-1, GSTR-3B support, tax liability, CN/DN register), each: service method → DTO → test → `reports:validate` invariant → screen/export.
3. Stop, verify (`php artisan test` reporting suite + `reports:validate` + `returns:validate` green), report, then proceed to M2 on confirmation.

Goal: **trustworthy, CA-respectable, operationally useful, scalable — without ERP bloat.**

---

## 11. Execution status (2026-06-03)

**Shipped & verified** (each: DTO → service → fixture test → `reports:validate` invariant → thin controller → nav + screen → CSV; reporting suite green, validators green):

- **M0/M1/M2** — export scaffolding, CA Tax Pack (GSTR-1/3B/tax-liability/CN-register), CA Ledger & Reconciliation (payment-recon/day-book/inventory-valuation). *(pre-existing, re-confirmed green)*
- **M3 Receivables & Liability** — #8 dues aging (DUE-1/2), #9 EMI visibility (EMI-1/2), #10 scheme liability (SCH-1/2), #11 metal/old-gold liability (MTL-1/2).
- **M4 (data-ready operational)** — #12 dead stock / inventory aging (DS-1/2), #13 karigar settlement (KAR-1/2), #14 purchase efficiency vs market rate (PUR-1).

**Deferred — each blocked on a specific dependency, NOT on reporting work (per "trust before features", a half-reconcilable report is not shipped):**

- **#15 Shrinkage / loss variance** — *blocked on the gram-accounting model gap.* Wastage is tracked in rupees, not grams, and finished-item fine weight is not cleanly linked back to the issuing job order, so an issued−received−wastage−produced gram reconciliation cannot be made trustworthy today. Needs the Material Flow "Tier 1" gram-accounting completion (emit a gram-level `wastage` MetalMovement at receipt) first. Karigar settlement (#13) already surfaces the *issued vs received vs wastage → outstanding* balance, which covers the operational need until then.
- **#16 Operator performance** — *blocked on a missing sales dimension.* `invoices` has no `user_id`/seller column, so sales/returns/discounts cannot be attributed to an operator. Requires a schema addition (`invoices.user_id`) **and** writing it on the finalization path — an accounting-write change, out of reporting scope. (Cash entries carry `user_id`, but that is payments, not sales.)
- **#17 Suspicious activity / audit** — *reclassified to platform/admin tier.* This is already produced by the `platform:detect-fraud` command + fraud-flag surfaces at the platform level. A shop-facing operational version is a product decision, not a CA/operational reporting gap.

**Next (open):** M5 (legacy report-controller extraction onto `Reporting\*` with parity tests), M6 (validation expansion), M7 (reporting UX/exports). The #15/#16 dependencies are pre-requisites to be scheduled deliberately, not bundled into a report PR.
