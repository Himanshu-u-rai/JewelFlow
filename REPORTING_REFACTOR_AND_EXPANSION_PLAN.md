# JewelFlow — Reporting Refactor & Expansion Plan

> **Companion to:** [REPORTING_SYSTEM_AUDIT.md](REPORTING_SYSTEM_AUDIT.md) (the diagnosis this plan acts on).
> **Date:** 2026-06-01
> **Nature:** Implementation-grade, phased, risk-aware architecture + expansion plan. This document is a **blueprint**, not an instruction to start coding. No code is written here.
> **Prime directive:** *The same business question must always produce the same answer everywhere.* Reports become **provably trustworthy** — owners trust them, operators understand them, CAs can file from them, without spreadsheet reconstruction outside JewelFlow.

---

## 0. Governing Principles (read first)

1. **Never touch the accounting core.** ImmutableLedger, DB triggers, persisted invoice/credit-note totals, `GstRateResolver` (CONSTITUTION §7), pricing authority — all frozen. Reporting is a **read layer over** these, never a parallel computation of them.
2. **One truth layer, many views.** All reports read through a single canonical query layer (`Reporting\*`). No controller writes its own aggregation again.
3. **Net-of-returns by construction.** Every financial figure is computed as `sales − credit notes` at the source, not bolted on per report.
4. **Accounting date, not row-insert date.** Reports filter on business/finalization date, never `created_at`.
5. **Provable, not asserted.** Every headline total has a test that locks it against a fixture and a reconciliation invariant.
6. **JewelFlow is not Tally.** It owns *transactional truth and compliance-ready exports*. It hands off *bookkeeping/filing* to a CA's tools via clean exports. The boundary is explicit (§7).

### What already shipped (changes the cost equation)
A prior GST-compliance sprint **already landed the schema**: `credit_notes` table with `cgst_amount/sgst_amount/igst_amount/place_of_supply_state_code/buyer_gstin`; `invoices.cgst_amount/sgst_amount/igst_amount` (backfilled); `invoice_items.hsn_code` (backfilled). The blocking gaps in the audit (no CN in GST report, no split shown) are therefore **read-layer fixes consuming existing columns**, not new schema work. This makes Tier-1 cheaper and lower-risk than the audit implied.

---

## 1. Reporting Architecture Redesign

### 1.1 The problem (from audit §6)
Every report is a standalone controller with its own inline query. Result: 3 different "sales" numbers, `created_at` misuse everywhere, duplicated period logic across `ClosingController` / `DashboardMetricsService` / `RetailerReportService`, repair revenue from two sources, zero report-total tests.

### 1.2 Target architecture — a thin canonical layer

```
Controllers (thin)  ── call ──▶  Reporting Services  ── use ──▶  Canonical Primitives  ── over ──▶  Persisted accounting tables
  (HTTP + view)                  (one per domain)                 (scopes, period, money)             (invoices, credit_notes,
                                                                                                        metal_movements, cash_transactions…)
```

Introduce a new namespace `App\Reporting`:

| Primitive | Type | Responsibility |
|---|---|---|
| `Reporting\ReportPeriod` | value object | A validated `[start, end]` + granularity (day/month/range). Single date-parsing/validation point. Kills the `preg_match` date-validation copy-paste in every controller. |
| `Reporting\Scopes\SaleScope` | Eloquent scope / trait on `Invoice` | The **canonical "this is a sale"** definition: `status = finalized`, filtered by **accounting date** (`finalized_at` fallback `created_at`). Used by *every* sales figure. |
| `Reporting\Scopes\AccountingDate` | scope helper | Applies the accounting-date column choice consistently (invoices → `finalized_at`; credit notes → `issued_at`; cash → transaction date; Dhiran → `payment_date`). |
| `Reporting\Money` | value object | Paisa-integer internal, `decimal(2)` boundary. Mirrors the existing `PricingEngine` discipline so report math can't drift via float. |
| `Reporting\SalesReportingService` | service | Net sales, taxable value, GST collected, by-rate breakdown, by-day trend, by-category — **all net of credit notes**. The single source for "how much did I sell." |
| `Reporting\GstReportingService` | service | Output tax, CN reversals, CGST/SGST/IGST split, GSTR-1/3B shaping. Reads persisted splits + `GstRateResolver` only. |
| `Reporting\InventoryReportingService` | service | Vault balance, aging (at cost), valuation, dead stock — reads `MetalLot`/`Item`. |
| `Reporting\ProfitReportingService` | service | True gross margin: revenue − COGS (`Item.cost_price`) − making-paid (karigar) over a period. One profit definition for dashboard + P&L. |
| `Reporting\Export\CsvReportExporter` | service | Generalizes the existing chunked-CSV streaming in `ExportController` into a reusable exporter (header + row-mapper + query). |

**Rule:** controllers may only call a `Reporting\*Service`. A code-review check rejects any new `Invoice::...->sum(...)` inside a controller or blade.

### 1.3 Why a service layer, not fat models
The accounting models are shared with the write path (POS, returns). Putting reporting aggregations on them risks coupling read concerns to write invariants. Keeping `App\Reporting` separate means report changes never touch the write path — smallest possible blast radius (consistent with the [[feedback_root_cause_fixes]] discipline).

### 1.4 Export architecture
- One `CsvReportExporter` (chunked, streamed) — already proven in `ExportController::streamCsvChunked`; promote it to `App\Reporting\Export`.
- GST exports (GSTR-1/3B/CN register) are **separate exporters** producing GSTN-portal-shaped CSV/JSON, each reading the same `GstReportingService` the on-screen report reads — so screen and export can never disagree.
- PDF/printable layouts share the same service data; only the view differs.

### 1.5 Report-total validation strategy (the trust spine)
Three layers, modeled on the existing `returns:validate` / `vault:reconcile` precedent:

1. **Unit fixtures** — seed N invoices + M credit notes with known numbers; assert `SalesReportingService` and `GstReportingService` return the exact expected totals. Locks headline numbers.
2. **Reconciliation invariants** (artisan `reports:validate`) — read-only, exits 1 on drift:
   - `SUM(invoice_items.gst_amount)` for finalized = GST report `gstCollected` (pre-CN).
   - `cgst+sgst+igst = gst` on every invoice/CN (already a returns:validate check — extend).
   - `net GST = output − CN reversals − ITC` reconciles to the GST screen.
   - Dashboard "today revenue" == SalesReportingService for today == Closing sales for today (the three numbers that disagree today must converge).
3. **Cross-report consistency test** — one test that calls every report for the same period and asserts the shared figures match.

---

## 2. Canonical Financial Semantics (the single accounting truth layer)

This becomes a short, authoritative doc (`docs/FINANCIAL_SEMANTICS.md`) that `App\Reporting` implements verbatim. Every definition below is the **only** definition.

| Concept | Canonical definition |
|---|---|
| **Sale** | An `Invoice` with `status = finalized`. Drafts and cancelled are **never** sales. |
| **Sale date (accounting date)** | `finalized_at` (fallback `created_at` only for legacy rows missing it). Never the raw insert timestamp going forward. |
| **Net sales** | `Σ finalized invoice.total − Σ credit_note.total` for the period. |
| **Taxable value** | `Σ invoice.subtotal (finalized) − Σ credit_note.subtotal`. |
| **GST collected (gross)** | `Σ invoice.gst (finalized)`. |
| **GST reversed** | `Σ credit_note.gst` (already policy-correct — only GST on actually-refunded components). |
| **Net GST liability** | `GST collected − GST reversed − ITC`. |
| **CGST/SGST/IGST** | Read persisted `*_amount` columns; `COALESCE(cgst, gst/2)` fallback for any legacy null. |
| **Return** | A settled `ReturnOrder`; its accounting effect is its `CreditNote`. |
| **Credit note** | The authoritative return document. Dated `issued_at` (= return-processing date), lands in the **current** GST period, references the original invoice. |
| **Exchange** | Two documents (CN + new invoice), never netted. Reports show both; net settlement is informational only. |
| **Scheme redemption** | Persisted `schemeRedemptions.amount`; reduces amount payable, not taxable value. |
| **Settlement** | `InvoicePayment` grouped by `mode`; sum of payments ≤ invoice total; outstanding = total − paid. |
| **Inventory valuation** | **At cost** (`Item.cost_price` + metal value at cost) for capital-tied-up reports; **at selling price** only when explicitly labelled "retail value." |
| **COGS** | `Item.cost_price` for the sold line; for items without cost_price, flagged "cost unknown," **excluded** from margin (never treated as zero profit). |
| **Profit (gross margin)** | `net sales − COGS − making-paid-to-karigar` over a date range. Can be negative. |

> Owner-facing labels translate these to plain English (§6) — but the math is fixed here.

---

## 3. Report Taxonomy (intentional hierarchy)

Reorganize the nav and the codebase around purpose, not accumulation:

| Tier | Reports | Audience |
|---|---|---|
| **A — Operational Daily** | Daily Closing, Cash Report, Cashbook, **Cash-Drawer Reconciliation** (new) | Owner/cashier, end-of-day |
| **B — Owner/Business** | Dashboard, **Real P&L** (rebuilt), Sales summary, Receivables aging (new) | Owner |
| **C — Inventory Control** | Gold Balance, Stock Aging (at cost), **Inventory Valuation** (new), **Dead Stock** (new), Vault/Metal Ledger | Owner/vault mgr |
| **D — Karigar/Manufacturing** | **Karigar Settlement** (new), **Metal Liability** (new), job-work P&L | Owner/karigar mgr |
| **E — Customer Intelligence** | Top customers, Occasions, **Repeat-customer / LTV / category-movement** (new) | Owner |
| **F — GST & Tax** | GST summary (fixed: net of CN + split), **GSTR-1**, **GSTR-3B support**, **CN/DN register** (new) | Owner + CA |
| **G — CA/Accountant** | **Day book**, **General ledger**, **Trial balance**, **Tax liability summary**, **Payment reconciliation** (new) | CA |
| **H — Audit & Compliance** | Metal/Vault Ledger, Reference Prices, AuditLog viewer, **Suspicious-activity** (extend `DetectFraud`), GDPR export | Owner/auditor/support |
| **I — Analytics & Forecasting** | Buying trends, seasonal demand, vault turnover (post-trust) | Owner |
| **J — Enterprise/Multi-Store** | Cross-store aggregation, operator analytics (architecture only, §8) | Multi-shop owner |

---

## 4. Existing Report Stabilization Plan

Classify and fix what's already shipped before adding new reports.

| Report | Class | Action |
|---|---|---|
| **GST report** | 🔴 accounting-risk | Subtract credit notes (read existing `credit_notes`), show CGST/SGST/IGST (read existing columns), switch to accounting date. **Read-layer only — schema exists.** |
| **P&L** | 🔴 misleading | Rebuild on `ProfitReportingService` (revenue − COGS − making-paid), date range, can show loss. Until rebuilt: **hide from nav** (do not ship a number that lies). |
| **Sales-definition drift** | 🔴 accounting-risk | Route Dashboard, GST, P&L, Sellers through `SaleScope`. One definition. |
| **`created_at` misuse** | 🔴 accounting-risk | `AccountingDate` scope everywhere. |
| **Worst-seller logic** | 🟠 misleading | Re-spec as "dead stock / never-sold" (left-join items with zero sales), not ascending sold-count. |
| **Stock-aging value** | 🟠 misleading | Add cost-basis value alongside retail value. |
| **Metal-exchange status filter** | 🟡 minor | Add `SaleScope` to exclude draft/cancelled. |
| **Repair revenue dual source** | 🔵 consistency | Single source via `RepairReportingService`; Closing reads it too. |
| **Daily Closing / Cash / Gold / Ledgers / Repairs / Exports** | 🟢 operator-safe | Keep; migrate onto shared primitives opportunistically, no behavior change. |

---

## 5. Missing Critical Reports (phased)

Built **only** on the canonical layer. Each reads a `Reporting\*Service`; none re-aggregates.

### Financial / CA
- **GSTR-1 sales report** — B2B/B2CS classification (invoice > ₹2.5L → B2B), HSN summary, CN section. Reads persisted splits + HSN (already present).
- **GSTR-3B support** — output tax / ITC / net liability summary.
- **Credit/Debit-note register** — all CNs with original-invoice reference, `cn_type` (full-cancel vs partial-return), period filter.
- **Tax liability summary** — month tax position at a glance.
- **Real P&L** — §4.
- **Trial balance / General ledger / Day book / Balance sheet** — *boundary decision in §7.* JewelFlow provides **day book** (chronological transaction journal — it already owns this data) and **GL/trial-balance/balance-sheet as exports for the CA's tool**, not as in-app double-entry statements. Building full double-entry in-app is explicitly out of scope (§11).
- **Payment reconciliation** — payments by mode vs invoice totals vs cash transactions.

### Jewellery-specific
- **Karigar settlement report** — issued vs received grams, making charged vs making paid, wastage, outstanding per karigar (extend `ReconcileKarigarBalances` data into a report view).
- **Metal liability report** — gold owed to customers (advances) vs gold on hand.
- **Old-gold purchase register** — already partly in Metal Exchange; formalize as a register with buyer details.
- **Inventory valuation (at cost)** — capital tied up in stock.
- **Shrinkage / loss variance** — issued − received − wastage gram gaps (reads `metal_movements`).
- **Dead stock / aging** — §4 (replaces broken worst-seller).
- **Purchase efficiency** — purchase rate vs market rate over time.

### Operations
- **Cash-drawer reconciliation** — opening + in − out = expected closing vs physical count entry.
- **Operator performance** — sales/returns/discounts by user (needs accounting-date + user scope).
- **Suspicious-activity / audit** — extend existing `DetectFraud` command into a report surface.
- **Scheme performance** — collections, redemptions, outstanding per scheme.
- **Receivables / dues aging** — EMI + credit balances bucketed (data already in exports).

### Customer Intelligence
- **Repeat customers, LTV, buying trends, category movement** — read `SalesReportingService`; post-trust (Tier 4).

---

## 6. Reporting UX & Readability Plan

| Problem | Strategy |
|---|---|
| Table fatigue / density | Every report leads with a **summary band** (3–5 headline numbers), table below. |
| Accounting jargon | **Owner mode** uses plain English (per [[feedback_simple_english_ui]]): "Gold added today," "Money in/out," "Tax you collected." **CA mode/export** uses precise terms. Same data, two labelings. |
| Raw ledger dumps | Daily Movement / Metal Ledger get human-readable event summaries (reuse the entity-event "plain language" pattern from the orchestration plan). |
| No drill-down | Summary rows link to underlying records (GST rate row → its invoices; closing sales → the invoice list). |
| Filters inconsistent | One shared filter component driven by `ReportPeriod` (date range + presets: today/week/month). P&L gets a date range. |
| Printable / mobile | Service-data shared; print layout + responsive single-column views per report. CA exports are CSV/PDF, not screen scraping. |

> **Hard rule:** owner-facing reports must be understandable without accounting expertise. The CA-grade depth lives in exports and a CA mode, never forced on the owner.

---

## 7. CA / Accountant Readiness — and the boundary

**A CA must be able to, inside JewelFlow:** verify GST (net of returns, with splits), reconcile payments, pull a CN/DN register, trace any adjustment to its source document, and export compliance-ready data — **without rebuilding numbers in a spreadsheet.**

**The accounting boundary (what JewelFlow owns vs hands off):**

| JewelFlow OWNS | JewelFlow EXPORTS / hands to CA tool |
|---|---|
| Transactional truth (invoices, CNs, payments, metal movements) | Full double-entry books / balance sheet finalization |
| GST output/reversal/liability computation from persisted data | GSTR filing submission to GSTN portal |
| GSTR-1/3B-shaped data + CN register | Income-tax computation, depreciation, etc. |
| Day book (chronological journal it already has) | Statutory financial statements |
| Reconciliation invariants (provable totals) | Audit sign-off |

> JewelFlow is the **system of record for jewellery transactions and GST data**, and the **source of clean exports** for the CA's accounting software. It is **not** the books-of-account engine. This boundary is what keeps it from becoming a Tally clone (§11).

---

## 8. Multi-Store / Future Scaling (architecture only — do not implement)

The canonical layer must not assume single-shop. Future-safe guarantees:
- `Reporting\*Service` methods take an explicit **shop-scope** (single id today; `[ids]` or "all my shops" later) instead of hard-coding `auth()->shop_id`.
- All aggregations group-able by `shop_id` so cross-store rollups are a `GROUP BY` addition, not a rewrite.
- `BelongsToShop` global scope stays; multi-store reads use an explicit owner→shops resolver, never bypass tenant isolation.
- Operator-level reporting requires a `user_id` dimension on sales scope — design it in now (column already on invoices), surface later.
- No store-aggregation UI is built; only the service signatures and grouping keys are made future-compatible.

---

## 9. Report Trust & Validation Strategy

The deliverable that makes reports *provably* trustworthy (modeled on `returns:validate`'s 12-check precedent):

1. **`reports:validate` artisan command** (read-only, exits 1 on drift) — runs the §1.5 reconciliation invariants per shop. Scheduled daily alongside `returns:validate`.
2. **Fixture total tests** — seeded scenarios lock GST, P&L, closing, sales numbers.
3. **Cross-report consistency test** — the "three numbers must agree" guard.
4. **Snapshot tests** — golden CSV/GSTR-1 exports diffed on change.
5. **Rollout validation** — before deprecating any old report, run old vs new in parallel and assert equality on production-shaped data.

Goal: a regression in any headline total fails CI, never ships silently (closes audit finding C5).

---

## 10. Rollout Sequencing (safe, incremental — no big-bang rewrite)

**Phase 0 — Foundation (no user-visible change):**
- Build `App\Reporting` primitives (`ReportPeriod`, `SaleScope`, `AccountingDate`, `Money`).
- Build `reports:validate` skeleton + fixture harness.
- *Validation:* new scopes return identical results to current queries on finalized-only data.

**Phase 1 — Trust fixes (highest priority, mostly read-layer):**
- GST report: net-of-CN + CGST/SGST/IGST split + accounting date (consumes existing columns).
- Converge the three sales definitions via `SaleScope` (Dashboard, GST, Closing, Sellers).
- Rebuild or hide P&L (`ProfitReportingService`).
- Add Tier-1 report-total tests.
- *Validation:* `reports:validate` green; dashboard/GST/closing agree for the same day.

**Phase 2 — CA readiness:**
- GSTR-1, GSTR-3B support, CN/DN register, payment reconciliation, day book, tax-liability summary.
- CA-mode exports.
- *Validation:* a CA can reconcile a full month without leaving JewelFlow.

**Phase 3 — Jewellery + operations coverage:**
- Karigar settlement, metal liability, inventory valuation (at cost), dead stock, shrinkage, cash-drawer reconciliation, receivables aging, operator performance, scheme performance.

**Phase 4 — Customer intelligence + analytics + multi-store surfacing.**

**Deprecation strategy:** an unsafe report (current P&L) is **hidden from nav immediately** (Phase 1) but the route stays until its replacement is validated, then removed — same pattern as the Transaction-History removal this session. Never leave a lying number reachable.

---

## 11. Constraints (hard guardrails)

**Do NOT:**
- Rebuild into Tally / full double-entry books-of-account engine.
- Add vanity analytics dashboards or charts-for-charts'-sake before totals are trustworthy.
- Duplicate accounting logic — reporting reads persisted truth, never recomputes pricing/GST independently (GST always via `GstRateResolver`).
- Break backend-authoritative accounting, ledger integrity, pricing authority, or session/transport stability.

**Preserve:**
- Persisted accounting truth, ImmutableLedger, DB triggers, `GstRateResolver` single GST path (CONSTITUTION §7), `BelongsToShop` tenant isolation, operational simplicity.

---

## 12. Definition of Done

The reporting system is "production-grade, CA-respectable, owner-friendly, jewellery-aware" when:

- [ ] One sales definition; dashboard, GST, closing, P&L agree for any period (`reports:validate` proves it).
- [ ] GST report is net of credit notes, shows CGST/SGST/IGST, uses accounting date; a CA files GSTR-1/3B from JewelFlow exports without a spreadsheet.
- [ ] P&L shows true gross margin (can be negative) over a date range — or is absent, never lying.
- [ ] Every headline total has a locking test; regressions fail CI.
- [ ] Owner-facing reports are plain-English; CA depth lives in exports/CA-mode.
- [ ] Karigar settlement, inventory-at-cost, receivables, cash-drawer reconciliation exist.
- [ ] Architecture is multi-store-ready (scoped services) without multi-store being built.
- [ ] No report re-aggregates outside `App\Reporting`; the accounting core is untouched.

---

## Appendix — Mapping audit findings → plan phases

| Audit finding | Addressed in |
|---|---|
| A1 GST omits CN / A7 no split | Phase 1 (read-layer; schema already shipped) |
| A2 fake P&L / A6 avg-rate | Phase 1 (`ProfitReportingService`) |
| A3/A4/A5 sales-definition & date drift | Phase 0+1 (`SaleScope`, `AccountingDate`) |
| O1 GSTR-1 / O2 karigar P&L / O3 receivables / O4 cash drawer | Phase 2–3 |
| O5 dead stock / O7 valuation-at-cost | Phase 3 |
| U1–U5 UX fatigue | §6, Phase 1+ |
| C1–C4 fragmentation | §1, Phase 0 |
| C5 no tests | §9, every phase |
| 8.5 analytics | Phase 4 |
