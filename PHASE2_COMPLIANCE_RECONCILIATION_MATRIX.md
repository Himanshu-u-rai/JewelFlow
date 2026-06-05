# Phase 2 — Compliance Reporting Reconciliation Matrix

> **Purpose:** permanent accounting traceability for the compliance family. Every
> compliance report is built **by construction** on top of the same canonical
> service that already computes the figure, so screen, PDF, Excel, CSV, the GST
> report, and the underlying tables can never disagree. This mirrors the Phase 1
> Sales / Invoice Register approach (reuse the single source of truth; never
> re-query in the renderer or the report).
>
> **Date:** 2026-06-05 · **Scope:** GST Report, GSTR-1, GSTR-3B, Credit Note Register, Day Book (frozen §22 COMPLIANCE + Day Book on the rigid-quality track).

---

## 1. The matrix

| Report | Source of Truth (canonical service → tables) | Reconciliation Target(s) |
|---|---|---|
| **GST Report** (`gst`) | `GstReportingService::summary()` → finalized `invoices` (`Invoice::salesIn` = finalized + `accountingBetween`) grouped by `gst_rate`, plus `credit_notes` reversals (by `issued_at`) | `invoices` table (Σ `subtotal` = taxable; Σ `gst`; Σ `COALESCE(cgst_amount, gst/2)` etc.); `credit_notes` (reversals); **GSTR-1** (`totalGst`); **GSTR-3B** (`outwardGst`) |
| **GSTR-1** (`gstr1`) | `TaxService::gstr1()` — reuses `GstReportingService::summary()` as its base totals; B2B/B2CS partition + per-HSN line aggregation over the same finalized invoices | **GST Report** (`taxable`, `cgst`, `sgst`, `igst`, `totalGst`); B2B + B2CS taxable = GST report taxable; HSN Σ taxable = line-level taxable |
| **GSTR-3B** (`gstr3b`) | `TaxService::gstr3b()` — outward tax from the same finalized invoices; less credit-note reversals | **GST Report** (`outwardGst` = `gstCollected`; `netGst` = `netGstLiability`); `outwardTaxable` = GST report `taxableAmount` |
| **Credit Note Register** (`cn-register`) | `TaxService::creditNoteRegister()` → `credit_notes` (by `issued_at`) joined to originating `invoices` | `credit_notes` table (Σ `subtotal` = `totalTaxable`; Σ `gst` = `totalGst`; Σ `total` = `totalValue`); **GST reversals** (`totalGst` = GST report `cnGstReversed`) |
| **Day Book** (`day-book`) | `LedgerService::dayBook()` → finalized `invoices` (sales), `credit_notes` (refunds), `invoice` cancellations, `cash_transactions` | **Underlying transaction source**: `invoices` (`salesTotal` = Σ finalized invoice `total`); `credit_notes` (`refundTotal`); `cash_transactions` (`cashIn`/`cashOut`); event count = rows across those sources |

---

## 2. Validation requirements (gate Phase 2 completion)

Each equality below is asserted in `tests/Feature/Reporting/ComplianceReportsTest.php`, reconciling **by construction** (the report's grand totals are the wrapped service's own totals).

**GST Report reconciles to:**
- `invoices` — Σ section taxable = `summary->taxableAmount`; Σ cgst/sgst/igst = `cgstCollected`/`sgstCollected`/`igstCollected`; Σ total_gst = `gstCollected`.
- `credit_notes` — the report's CN reversals = `cnGstReversed`.
- **GSTR-1** — GST report `total_gst` total = GSTR-1 `totalGst`.
- **GSTR-3B** — GST report `total_gst` total = GSTR-3B `outwardGst`.

**GSTR-1 reconciles to GST Report:**
- B2B + B2CS taxable = GST report taxable; cgst/sgst/igst totals = GST report splits; `totalGst` = GST report `gstCollected`.

**GSTR-3B reconciles to GST Report:**
- `outwardGst` = GST report `gstCollected`; `outwardTaxable` = `taxableAmount`; `netGst` = `netGstLiability`.

**Credit Note Register reconciles to:**
- `credit_notes` table — totals = Σ over `credit_notes` rows in the period.
- **GST reversals** — `totalGst` = GST report `cnGstReversed`.

**Day Book reconciles to underlying transaction source:**
- `salesTotal` = Σ finalized `invoices.total`; `refundTotal` = Σ `credit_notes.total`; `cashIn`/`cashOut` = Σ `cash_transactions`; running balance ties out across the ordered events.

---

## 3. Why this holds permanently

1. **Single source of truth.** No compliance report runs its own re-derivation of GST figures; each calls `GstReportingService` / `TaxService` / `LedgerService`. Those services are already the authoritative, tested path (`GstReportingTest`, `TaxServiceTest`). A change to the GST definition changes all of them together.
2. **Reuse, not re-sum.** `TaxService::gstr1` and `gstr3b` take `GstReportingService::summary()` as their base — so GSTR-1 and GSTR-3B cannot drift from the GST report.
3. **Rigid outputs.** Compliance reports expose the `Fixed` profile only, all-mandatory columns, no toggles/sensitive overrides — so two exports of the same period are byte-reproducible (frozen §9). The `ReportDefinition` constructor enforces this; a non-rigid compliance definition fails to construct.
4. **One renderer path.** Screen/PDF/Excel/CSV all consume the one `ReportDataset` (frozen §3.1) — they format differently but report identical numbers.

---

*Reconciliation is by construction. No report-specific export path, no alternate architecture.*
