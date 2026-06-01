# JewelFlow — Reporting System Audit

> **Scope:** Operational + trust audit of the entire web SaaS reporting surface.
> **Date:** 2026-06-01
> **Nature:** Diagnosis, mapping, and trust analysis. **No reports are redesigned or modified by this document.** Findings end in a prioritized refinement list, not implementation.
> **Method:** Direct inspection of every report controller, the services they call, the views they render, the export pipeline, and route/permission wiring.

---

## 0. Executive Verdict

JewelFlow's reporting layer is **operationally adequate for a single-shop pilot but is NOT yet financially trustworthy as the authoritative source for tax filing or true profitability.** The accounting *core* (ledgers, triggers, persisted invoice totals) is strong; the *reporting layer on top of it* is fragmented, evolved organically, and contains at least three high-severity trust risks where reports drift from accounting truth.

The single most important structural finding:

> **There is no shared reporting layer.** Every report is a self-contained controller writing its own aggregation query against raw models. There is no `ReportingService`, no shared date-range primitive, no shared "what counts as a sale" definition, and no shared period/status filter. As a result the same business question ("how much did I sell today?") is answered three different ways by three different reports — and they disagree.

The three findings that block "production-grade, accountant-trustworthy" status:

1. **GST report ignores credit notes / returns entirely** → GST liability is overstated; a CA cannot file GSTR-1 from this screen. (Accounting-risk)
2. **The P&L report's "Profit" is not profit** → it omits cost of goods entirely and can never show a loss. It is a charges-collected figure mislabeled as profit. (Misleading metric / accounting-risk)
3. **"What counts as a sale" is defined inconsistently** → Dashboard uses `!= cancelled`, GST/Closing use `= finalized`, P&L applies **no status filter at all** (drafts and cancelled invoices are counted). (Consistency / accounting-risk)

Everything else is real but lower-stakes.

---

## 1. Full Reporting Surface Inventory

### 1.1 Core financial / operational reports (web, permission-gated)

| Report | Route | Controller | View | Data source | Authority |
|---|---|---|---|---|---|
| Gold Balance | `report.gold` | `ReportController::gold` | `reports/gold.blade.php` | `MetalLot` (sum `fine_weight_remaining` by metal+purity) | **Authoritative** (vault truth) |
| Daily Metal Movement | `report.daily` | `DailyReportController` | `report_daily.blade.php` | `MetalMovement` grouped by type | **Authoritative** (raw ledger) |
| Cash Report | `report.cash` | `CashReportController` | `report_cash.blade.php` | `CashTransaction` grouped by type + payment_mode | **Authoritative** (persisted) |
| P&L | `report.pnl` | `PnlController` | `report_pnl.blade.php` | `Invoice` + `InvoiceItem` + `MetalMovement` | **Recomputed / misleading** |
| GST | `report.gst` | `GstController` | `reports/gst.blade.php` | `Invoice` (persisted `subtotal/gst/total`) grouped by rate | **Partly authoritative, incomplete** (no CN) |
| Daily Closing | `report.closing` | `ClosingController` | `report_closing.blade.php` | `Invoice` + `MetalMovement` + `CashTransaction` + `InvoicePayment` | **Authoritative** (persisted) |
| Repairs | `report.repairs` | `RepairReportController` | `report_repairs.blade.php` | `Repair` (persisted sums) | **Authoritative** |
| Metal Exchange | `report.metal-exchange` | `MetalExchangeReportController` | `report_metal_exchange.blade.php` | `InvoicePayment` (old_gold/old_silver) + weekly `MetalLot` | **Authoritative** (persisted) |
| Reference Prices | `report.reference-prices` | `ReferencePriceHistoryController` | `reports/reference-prices.blade.php` | `ShopMetalReferencePrice` | **Memo only** (by design, non-accounting) |
| Metal Ledger | `ledger.index` | `LedgerController` | `ledger.blade.php` | `MetalMovement` paginated list | **Authoritative** (raw audit) |
| Cashbook | `cashbook.index` | `CashBookController` | `cashbook/index.blade.php` | `CashTransaction` list + today/month stats | **Authoritative** |
| Vault Ledger | `vault.ledger` | `BullionVaultController::ledger` | `vault/ledger.blade.php` | `MetalMovement` (vault scope) | **Authoritative** |

### 1.2 Retailer analytics (web, `edition:retailer`)

| Report | Route | Controller | Source | Authority |
|---|---|---|---|---|
| Stock Aging | `report.stock-aging` | `RetailerDashboardController` → `RetailerReportService::stockAging` | `Item` (in_stock), PHP bucketing | Authoritative count; **value uses selling_price** |
| Best/Worst Sellers | `report.sellers` | `RetailerReportService::bestSellers/worstSellers` | `InvoiceItem`⋈`Invoice`⋈`Item` | **Misleading** (no status filter; "worst" logic flawed) |
| Customer Occasions | `report.occasions` | `RetailerDashboardController::occasions` | `Customer` (all loaded into PHP) | Authoritative; **perf risk at scale** |
| WhatsApp/Catalog | `report.whatsapp` | `CatalogController` | catalog collections | Operational, not financial |

### 1.3 Dashboards (summaries)

| Surface | Controller | Source | Notes |
|---|---|---|---|
| Owner Dashboard (web) | `DashboardController` → `DashboardMetricsService::build` (cached 300s) | Invoices, Items, Repairs, InstallmentPlan, invoice_items⋈items | Today revenue/profit, 7-day & 30-day trend, top customers, reorder alerts |
| Mobile Dashboard (API) | `Api/Mobile/DashboardController` | Same `DashboardMetricsService` + daily rate | Good reuse; **inherits all DashboardMetricsService issues** |
| Admin Revenue Analytics (platform) | `Admin/RevenueAnalyticsController` | `shop_subscriptions` | MRR/churn — platform-level, separate concern |

### 1.4 Dhiran (pawn/loan) reports — `dhiran.reports.*`

| Report | Method | Source / date basis |
|---|---|---|
| Active loans | `reportActive` | `DhiranLoan` active |
| Overdue | `reportOverdue` | `DhiranLoan` overdue |
| Interest collected | `reportInterest` | `DhiranPayment.payment_date` ✅ (accounting date) |
| Forfeiture | `reportForfeiture` | `DhiranLoan` forfeited |
| Cashbook | `reportCashbook` | `DhiranCashEntry.entry_date` ✅ |
| Profitability | `reportProfitability` | `DhiranLoan.loan_date` |

> **Notable:** The Dhiran reports are the *best-designed* family in the system — they filter on accounting dates (`payment_date`, `entry_date`), validate date ranges, and use proper period windows. The core retail reports do none of this consistently.

### 1.5 Exports (CSV) — `ExportController`, `reports.export` permission

| Export | Method | Source |
|---|---|---|
| Customers | `exportCustomers` | customers + invoice/EMI/scheme/gold aggregates |
| Products | `exportProducts` | items |
| Invoices/Sales | `exportInvoices` | invoices + payments + offers + scheme redemptions (**all statuses**, status column included) |
| Gold Ledger | `exportGoldLedger` | InvoicePayment (old metal) |
| Cash Transactions | `exportCashTransactions` | CashTransaction |
| Export All | `exportAll` | combined (manufacturer-only) |

Plus GDPR export (`Admin/GdprExportController`) — compliance, out of scope here.

### 1.6 Recently removed
- **Transaction History report** — removed this session (commit `aaafd32`) as unintelligible. Correct call; it duplicated cashbook/ledger with no clear purpose.

---

## 2. Categorized Reporting Matrix

| Category | Reports | Who uses it | Cadence |
|---|---|---|---|
| **Operational daily** | Daily Closing, Cash Report, Daily Metal Movement, Cashbook, Owner Dashboard | Owner/cashier end-of-day | Daily |
| **Owner/business** | Dashboard trends, Stock Aging, Best/Worst Sellers, Occasions, Metal Exchange | Owner weekly review | Weekly |
| **Accountant/GST** | GST report, Invoice CSV export, Closing | CA at filing time | Monthly |
| **Inventory-control** | Gold Balance, Stock Aging, Vault Ledger, Metal Ledger | Owner/vault manager | Weekly |
| **Customer-intelligence** | Top Customers (dashboard), Occasions, Customer CSV export | Owner | Ad-hoc |
| **Audit/compliance** | Metal Ledger, Vault Ledger, Reference Prices, AuditLog viewer, GDPR export | Owner/auditor/support | As-needed |
| **Loan (Dhiran) module** | 7 Dhiran reports | Loan operator | Daily/monthly |

### 2.1 What ACTUALLY matters daily inside a real jewellery shop

Ranked by genuine daily operational weight:

1. **Daily Closing** — the single end-of-day "did my cash, gold, and sales reconcile?" view. This is the report owners will actually open every evening. It is the most important report in the system and is reasonably built (persisted, finalized-filtered).
2. **Cash Report / Cashbook** — "how much cash came in/out today" against the physical drawer.
3. **Gold Balance** — "how much gold do I have" (vault truth).
4. **Dashboard** — at-a-glance today's revenue + reorder alerts.
5. **GST report** — *not* daily, but the highest-stakes monthly report (and the most broken).

Everything else (sellers, occasions, aging, metal-exchange, reference prices) is periodic, not daily. The P&L report, despite sounding central, is the **least trustworthy** and should not be relied on daily.

---

## 3. Reporting Condition Audit (per major report)

### 3.1 P&L (`PnlController`) — **WEAK / MISLEADING**
- **Correctness:** `$profit = making + stones + wastageRecovered`. This omits **cost of goods sold entirely** — gold cost, purchase cost, making paid to karigar. It can never be negative. It is "charges collected," not profit.
- **No status filter** — `Invoice::where(shop)->whereDate(created_at)->sum('total')` counts **draft and cancelled invoices**. Sales figure is inflated.
- **Recomputed gold value:** `goldSold × avg('gold_rate')` uses the *average* gold rate across all invoices that day, not the actual per-invoice rate. Drifts from the real figure on mixed-rate days.
- **Single day only**, no date range, no month view.
- **Contradicts the dashboard's own profit** (which *does* use `line_total − cost_price`). Two profit definitions, neither labelled.
- **Verdict:** abandoned-quality. Either fix to a true gross-margin definition or remove until it can be trusted.

### 3.2 GST (`GstController`) — **INCOMPLETE (high stakes)**
- **Correctness of what it shows:** good — sums persisted `subtotal/discount/gst/total` grouped by rate, filters `status = finalized`. The figures it displays are authoritative.
- **What it omits:** **credit notes / returns are not subtracted.** There is no credit-note query in the controller and no CN section in `reports/gst.blade.php`. Output tax liability is therefore **overstated** by the GST on every return. (The GST-compliance plan's Phase 1 — "credit notes in GST report" — was specified but **never shipped**.)
- **No CGST/SGST/IGST split** surfaced (the report shows aggregate GST only).
- **No HSN summary**, no GSTIN, no GSTR-1-shaped export.
- **Date basis is `created_at`**, not invoice/finalization date.
- **Verdict:** usable as an internal collected-GST summary; **not** usable for actual GST filing without manual CN reconciliation in Excel.

### 3.3 Daily Closing (`ClosingController`) — **STRONG**
- Persisted totals, `status = finalized`, single-aggregate queries (well-optimized), gold-in/out from typed `MetalMovement`, payment-mode breakdown via join (no N+1), 7-day trend. Date input validated.
- **Minor:** date basis is `created_at`; repairs pulled from `CashTransaction type=repair` (a parallel path to the Repairs report — two sources for "repair revenue").

### 3.4 Cash Report / Cashbook — **SOLID, but no balance reconciliation**
- Both group `CashTransaction` correctly. Cashbook has search + date + type filters and today/month stat cards.
- **Gap:** neither shows an opening→closing running balance reconciled against a physical count. Owner gets totals, not "your drawer should contain ₹X."

### 3.5 Gold Balance / Metal Ledger / Vault Ledger — **STRONG**
- Read directly from vault truth (`MetalLot`, `MetalMovement`). Grouped by metal+purity per CONSTITUTION Article XIII/XIV. Ledger is a raw paginated movement list (no totals, no filters — it's an audit dump, which is acceptable for its purpose).

### 3.6 Repairs (`RepairReportController`) — **SOLID**
- Date-range + status filters, persisted sums, `total_cash` only counts `delivered`. Good. Paginated with `withQueryString`.

### 3.7 Metal Exchange (`MetalExchangeReportController`) — **GOOD, minor gap**
- Two views (transactions + weekly lots). Date validated. Summaries computed in PHP via collection sums (fine at small scale).
- **Gap:** the transaction view filters invoice by `shop_id` and date but **not by status** — draft/cancelled invoices' old-metal payments may appear.

### 3.8 Stock Aging — **GOOD (count), questionable (value)**
- Cached 10 min. Buckets by age. **Value uses `selling_price`** so "aged inventory value" is retail value, not cost — overstates capital tied up. Loads all in_stock items into PHP (perf risk for large catalogs).

### 3.9 Best/Worst Sellers — **MISLEADING**
- No invoice status filter → cancelled/draft sales counted in "sold_count."
- **"Worst sellers" is structurally wrong:** it's built from `InvoiceItem⋈Invoice⋈Item`, so it can only rank items **that were sold at least once**, ordered ascending. A category with **zero** sales — the actual worst seller and the one the owner most needs to see — never appears. The report cannot answer the question it's named for.

### 3.10 Dashboard (`DashboardMetricsService`) — **MIXED**
- `todaysRevenue` excludes cancelled ✅ but includes drafts. `invoicesToday` counts **all** statuses including drafts.
- `todaysProfit` uses `line_total − COALESCE(cost_price, 0)` → genuine gross profit, **but** items with null `cost_price` count their entire line as profit (inflated), and items with no `item_id` (quick-bill / manufactured-on-the-fly) are excluded entirely.
- Well-cached (300s), trend queries optimized with keyBy. Reused cleanly by mobile.

---

## 4. Financial Authority Audit (where totals originate)

### 4.1 The authority spectrum

| Authority level | Reports |
|---|---|
| **Reads persisted accounting values** (correct) | GST (what it shows), Closing, Cash, Cashbook, Repairs, Metal Exchange, Invoice export, Gold/Vault/Metal ledgers |
| **Recomputes independently from raw parts** (drift risk) | **P&L** (`goldSold × avg(rate)`, charges-as-profit), Dashboard profit (`line_total − cost_price`) |
| **Reads true vault/ledger state** (authoritative by design) | Gold Balance, all metal ledgers |

### 4.2 Specific drift-from-truth risks

| Area | Status | Detail |
|---|---|---|
| **GST totals** | ⚠️ overstated | Returns/credit notes never subtracted. No CGST/SGST split surfaced. |
| **Discounts** | ✅ | Read from persisted `invoices.discount`. |
| **Round-off** | ✅ | Persisted `invoices.round_off` (carried in exports/closing). |
| **Payment settlement** | ✅ | `InvoicePayment` grouped by mode; consistent across closing/export. |
| **Old gold exchange** | ✅ (mostly) | Persisted `InvoicePayment` old_gold/old_silver; metal-exchange view lacks status filter. |
| **Scheme redemption** | ✅ | Persisted `schemeRedemptions.sum(amount)` in export. |
| **Profit/margin** | ❌ | **Two conflicting definitions**, neither trustworthy. P&L ignores COGS; Dashboard mishandles null cost_price. |
| **Karigar accounting** | ⚠️ partial | No dedicated karigar P&L/settlement report in the web reporting surface; making-paid never enters any profit figure. |

### 4.3 The "what counts as a sale" inconsistency (systemic)

| Report | Status filter | Date basis |
|---|---|---|
| GST | `= finalized` | `created_at` |
| Closing | `= finalized` | `created_at` |
| **P&L** | **none (drafts + cancelled counted)** | `created_at` |
| Dashboard | `!= cancelled` (drafts counted) | `created_at` |
| Best/Worst Sellers | **none** | `created_at` |
| Dhiran reports | n/a | **accounting dates** (`payment_date`/`entry_date`) ✅ |

A single shop opening P&L, the dashboard, and the GST report for the same day will see **three different sales numbers**. This is the most corrosive trust problem in the system — not because any one is catastrophic, but because their disagreement teaches the owner that no report can be trusted.

### 4.4 Accounting-date problem (systemic)

Every core retail report filters on `created_at` (the row-insert timestamp), not on a business/accounting date. A draft created on the 31st and finalized on the 1st lands in the wrong month for GST. `finalized_at` exists on invoices but no report uses it. The Dhiran module already does this correctly — the pattern exists in the codebase; it just wasn't applied to retail.

---

## 5. Reporting UX Audit

| Dimension | Assessment |
|---|---|
| **Readability** | Closing/Cash/GST are clean. Metal Ledger and Daily Movement are raw dumps with no summaries. |
| **Filter ergonomics** | Inconsistent: Repairs/Cashbook/Metal-Exchange have good filters; Daily/Gold/Ledger have none; P&L is single-day with no range. |
| **Export ergonomics** | Strong — chunked CSV streaming, rich invoice columns, status column present. Best-built part of the reporting surface. |
| **Mobile usability** | Only the dashboard has a mobile API. No mobile reports (acceptable for now). |
| **Loading performance** | Dashboard cached well. **Risks:** Stock Aging and Occasions load full collections into PHP; metal-exchange transaction view uses `->get()` then PHP sums. |
| **Drill-down** | Essentially none. No report links to the underlying invoices/movements. Owner can't click a GST rate row to see its invoices. |
| **Terminology** | Mixed. "Metal Movement," "fine weight," "wastage recovered" leak domain vocabulary — conflicts with the [[feedback_simple_english_ui]] requirement that owner-facing text use plain English. |

**Overwhelm / fatigue points:**
- **Daily Metal Movement** and **Metal Ledger** present raw type-grouped rows with no narrative — owners won't understand "manufacture / repair_issue / old_metal_in."
- **No unified "end of day" answer** — closing is good but the owner still cross-references cash, gold, and sales mentally.
- **P&L actively misleads** — an owner reading "Profit ₹12,000" believes a falsehood.

---

## 6. Report Architecture Audit

### 6.1 Designed systematically or evolved organically?
**Evolved organically.** Evidence:

- **No shared reporting primitives.** Each report is a standalone controller with its own inline query. There is no `ReportingService`, no shared `DateRangeFilter`, no shared `SalesScope` (finalized-only), no base report controller.
- **Duplicated aggregation logic.** "Sum invoice totals for a period grouped by day" appears independently in `ClosingController`, `DashboardMetricsService`, and `RetailerReportService` — each written differently.
- **Repair revenue has two sources.** Closing reads `CashTransaction type=repair`; the Repairs report reads the `Repair` model's `final_cost`. These can disagree.
- **Inconsistent filters.** Status/date semantics differ per report (Section 4.3/4.4).
- **One genuinely good pattern exists but isn't shared:** the Dhiran reports' accounting-date discipline. It was never generalized.
- **No reporting-layer tests.** Test coverage exists only for reference-price and vault-visibility. There are **zero** tests asserting GST totals, P&L correctness, closing reconciliation, or dashboard metrics. Any regression in a report total ships silently.

### 6.2 Maturity verdict
**Fragmented, not architecturally mature.** The accounting *substrate* it reads from is mature and well-protected (immutable ledgers, triggers, persisted totals). The *reporting layer* is a collection of independently-grown screens. This is fixable without touching the accounting core — it needs a thin shared layer (scopes + date primitive + one sales definition), not a rewrite.

---

## 7. Real-World Jewellery-Shop Sufficiency

| Shop type | Sufficient today? | Gaps |
|---|---|---|
| **Small shop (10–30 sales/day, has CA)** | Mostly | Closing/cash/gold cover daily ops. GST report needs manual CN reconciliation. P&L unusable. |
| **Medium retailer** | Partially | Needs date-range P&L, trustworthy margin, category profitability, better aging (at cost). |
| **Multi-operator store** | No | No per-operator/per-counter reporting; no shift reconciliation; concurrency in cash report untested. |
| **Accountant workflow** | No | GST report omits credit notes and CGST/SGST split; no GSTR-1 export; date basis is system timestamp not invoice date. |

### 7.1 Critical missing reports
1. **GSTR-1-ready GST report** with credit notes, CGST/SGST/IGST split, HSN, GSTIN, period export. *(Highest priority — a filing shop cannot rely on the current screen.)*
2. **A true P&L** with COGS (gold cost + making paid + purchase cost) over a **date range**, capable of showing a loss.
3. **Karigar settlement / job-work P&L** — making charged vs making paid; currently invisible in reporting.
4. **Outstanding / receivables report** — EMI + credit balances aggregated (data exists in exports, no report view).
5. **Cash drawer reconciliation** — opening + movements = expected closing vs physical count.
6. **Inventory valuation at cost** (aging value currently uses selling price).
7. **Never-sold / dead-stock report** — the question "worst sellers" pretends to answer but cannot.

### 7.2 Where operators will fall back to manual calculation
- **GST filing** → Excel reconciliation of returns against the GST screen (the "CA's parallel spreadsheet" risk).
- **True profit** → owner will not trust the P&L screen and will compute margin by hand or in Tally.
- **Receivables** → manual, since no aggregated outstanding view exists as a report.

---

## 8. Findings Classified

### 8.1 🔴 Accounting-risk issues (reports may drift from accounting truth)
| # | Finding | Location |
|---|---|---|
| A1 | **GST report omits credit notes** — liability overstated; not filing-ready | `GstController`, `reports/gst.blade.php` |
| A2 | **P&L "Profit" ignores COGS** and can never be negative — fundamentally misstates profit | `PnlController:50` |
| A3 | **P&L counts draft + cancelled invoices** (no status filter) | `PnlController:18` |
| A4 | **Inconsistent "what counts as a sale"** across Dashboard / GST / P&L / Sellers | systemic |
| A5 | **All retail reports use `created_at`, not accounting/finalization date** — wrong-period risk at month boundaries | systemic |
| A6 | **P&L gold value uses `avg(gold_rate)`** instead of actual per-invoice rate | `PnlController:38-42` |
| A7 | **No CGST/SGST/IGST split** in GST report | `reports/gst.blade.php` |

### 8.2 🟠 Operational blind spots (missing reports operators need)
| # | Finding |
|---|---|
| O1 | No GSTR-1-shaped export for actual filing |
| O2 | No date-range/true P&L; no karigar settlement P&L |
| O3 | No receivables/outstanding aggregate report |
| O4 | No cash-drawer reconciliation (opening→closing vs physical) |
| O5 | No dead-stock / never-sold report |
| O6 | No per-operator / shift reporting (blocks multi-operator shops) |
| O7 | Inventory aging valued at selling price, not cost |

### 8.3 🟡 UX / reporting fatigue
| # | Finding |
|---|---|
| U1 | Raw ledger/movement dumps with no summaries or plain-English labels |
| U2 | No drill-down from any summary to underlying records |
| U3 | Domain vocabulary in owner-facing reports (violates [[feedback_simple_english_ui]]) |
| U4 | Inconsistent/absent filters (P&L single-day; Daily/Gold/Ledger unfiltered) |
| U5 | No single trustworthy "end of day" number (closing is close but not consolidated) |

### 8.4 🔵 Consistency problems (architecture/fragmentation)
| # | Finding |
|---|---|
| C1 | No shared reporting layer — every report reimplements aggregation |
| C2 | Duplicated period-sales logic across 3 services |
| C3 | Repair revenue sourced two different ways (CashTransaction vs Repair model) |
| C4 | Dhiran module uses correct accounting-date discipline; retail does not (pattern exists, not shared) |
| C5 | **Zero automated tests on report totals** — silent regression risk |

### 8.5 🟢 Future analytics opportunities (not now)
- Return-rate by category, karigar efficiency (grams in vs out), customer LTV/RFM, seasonal demand, margin-by-category, vault turnover. All are post-trust-fix; building analytics on top of untrustworthy totals would amplify the problem.

---

## 9. Refinement Priorities (ordered)

> Diagnosis only — sequencing recommendation, not an instruction to implement.

**Tier 1 — Trust (do before any shop files GST or trusts profit):**
1. **A4 + A3 + A5:** Introduce one shared sales definition (finalized-only) and an accounting-date basis, applied to Dashboard, GST, P&L, Closing, Sellers. (Closes the "three different numbers" problem at the root — consistent with [[feedback_root_cause_fixes]].)
2. **A1 + A7:** Add credit notes and CGST/SGST split to the GST report (the GST-compliance plan already specifies exactly how — it was never shipped).
3. **A2 + A6:** Either fix P&L to a true COGS-based gross margin over a date range, or hide it until it can be trusted. Do not leave a mislabeled "Profit" in production.

**Tier 2 — Coverage (medium retailer / accountant readiness):**
4. GSTR-1 export (O1).
5. Receivables report (O3) and cash-drawer reconciliation (O4).
6. Karigar settlement P&L (O2).

**Tier 3 — Architecture (prevents recurrence):**
7. Extract a shared reporting layer: `SalesScope` (finalized + accounting date), a `DateRange` primitive, and a single period-sales query (C1–C4).
8. Add report-total tests (C5) — lock GST, closing, and P&L numbers against fixtures.

**Tier 4 — UX & analytics:** drill-down, plain-English labels, dead-stock report, then the analytics opportunities in 8.5.

---

## 10. One-Paragraph Bottom Line

JewelFlow's reporting layer sits on a strong, immutable accounting core but has grown one screen at a time without a shared foundation. The daily operational reports (closing, cash, gold) are trustworthy and genuinely useful. The high-stakes reports are not: the GST report silently ignores returns and cannot file GSTR-1, the P&L reports a number that is not profit and counts drafts, and the system answers "how much did I sell" three incompatible ways. None of these require touching the accounting engine — they require one shared sales definition, an accounting-date basis, the already-planned GST credit-note work, an honest P&L, and a thin set of tests to keep totals from drifting again. Until those land, JewelFlow is an excellent *operational* reporting system and an *untrustworthy* accounting/tax reporting system.
