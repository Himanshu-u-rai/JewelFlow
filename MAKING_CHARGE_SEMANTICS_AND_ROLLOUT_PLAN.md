# Making Charges — Semantics & Pricing Integrity Audit + Rollout Plan
*Date: 2026-06-03 — Status: DESIGN ONLY. No implementation. No pricing-semantics mutation.*

> **Goal:** evolve making charges from "manual fixed amount" into a multi-mode system
> (percentage of metal value / per-gram / fixed) **without** destabilising the hardened
> financial core — pricing truth, invoice immutability, GST integrity, reconciliation,
> historical reproducibility, and web/mobile parity.
>
> This document is the audit that must exist **before** any code is written.

---

## 0. Executive Summary (the one fact that makes this safe)

Across the **entire** codebase, a making charge is — today — a **resolved scalar rupee
amount**. It is *entered* as an amount, *stored* as an amount (`invoice_items.making_charges`),
*summed* as an amount (P&L), *refunded* as an amount (returns), *displayed* as an amount
(web + mobile + exports), and *taxed* implicitly because it is folded into `line_total →
subtotal → taxable`. **No table, query, report, export, or API anywhere records *how* a
making charge was derived.**

This is the key to a safe evolution:

> If the three new modes all **resolve to a rupee amount at quote/registration time** and
> that resolved amount is persisted into the existing `making_charges` column exactly as it
> is today, then **GST, P&L, returns/CN, exports, reconciliation, mobile read-paths, and the
> accounting-guard trigger continue to work unchanged.** The mode + raw value become **new
> additive metadata**, never a replacement for the amount that is invoice truth.

The work is therefore: (a) add `making_charge_type` + `making_charge_value` as **new nullable
metadata** at the input boundary (item registration and the manufacturer/quick-bill quote
input); (b) **resolve** them to the existing rupee amount inside the **canonical PricingEngine**;
(c) snapshot type+value+resolved amount onto persistence rows; (d) leave every downstream
consumer reading the resolved amount untouched. Risk is concentrated in exactly two places
(the HMAC-signed quote canonical form, and the retailer fixed-price flow) — both analysed below.

Risk classification: **MEDIUM overall** — additive, but it touches the signed pricing
contract and must preserve historical reproducibility. No HIGH-risk mutation of accounting
identities is required.

---

## 1. Current-State Audit — complete making-charge map

### 1.1 Where it is STORED (persistence)

| Table | Column | Type | Meaning today | Set by |
|---|---|---|---|---|
| `items` | `making_charges` | numeric (flat ₹) | per-piece making, fixed at registration | `ShopPricingService`, `ItemManufacturingService`, `BulkImportService` |
| `invoice_items` | `making_charges` | numeric **NOT NULL** | making snapshot at sale (component of `line_total`) | `SalesService` (mfr), `RetailerSalesService` (retail) |
| `invoice_items` | `stone_amount` | numeric NOT NULL | stone snapshot (sibling component) | same |
| `invoice_items` | `line_total` | numeric NOT NULL | metal + making + stone (+wastage in QB) | same |
| `quick_bill_items` | `making_charge` | numeric (flat ₹) | making per QB line | `QuickBillService` |
| `quick_bill_items` | `wastage_percent` | numeric (**%**) | **precedent: a percentage input already exists for wastage** | `QuickBillService` |
| `stock_purchase_items` | `making_charges` | numeric | making paid to supplier (cost side) | `StockPurchaseService` |
| `karigar_invoice_lines` | making fields | numeric | karigar labour settlement | `KarigarInvoiceService` |
| `invoices` | `wastage_charge` | numeric | **separate** from making (manufacturer wastage recovery) | `PricingEngine` / `SalesService` |
| `return_line_items` | `policy_breakdown` (JSONB) | — | `making_retained` etc. captured at settle | `RefundPolicyResolver` |
| `credit_notes` | `wastage_charge` | numeric | mirrored on CN | returns |

**No column anywhere stores a making-charge "type" or "rate".** Everything is a resolved ₹ amount.

### 1.2 Where it is CALCULATED

| Flow | Engine path | Making semantics today |
|---|---|---|
| **Manufacturer POS** | `PricingEngine::computeManufacturer()` | `line_total = metalValue + making + stone`, where `making = QuoteInput.making` — a **flat ₹ input**. Wastage is *separate* (`item.wastage × goldRate × wastage_recovery_percent`). |
| **Retailer POS** | `PricingEngine::computeRetailer()` | making is **baked into `item.selling_price`** at registration. The engine only **echoes** `item.making_charges` into line metadata; `line_total = selling_price`. There is **no gold rate at retailer sale time**. |
| **Quick Bill** | `QuickBillService::persist()` | `line_total = metalValue + making + stoneCharge + hallmark + rhodium + other + wastageAmount − lineDiscount`; `making` flat input, `wastageAmount = metalValue × wastage_percent/100` (**already a percentage**). |
| **Repair** | `PricingEngine::computeRepair()` | no item / no making — typed amount only. Out of scope. |
| **Exchange** | `PricingEngine::computeForExchange()` → `computeManufacturer()` | same flat-making manufacturer path. |
| **Item registration** | `ShopPricingService`, `ItemManufacturingService` | `making_charges` stored flat; `overhead_cost = making + stone + hallmark + rhodium + other`; manufacturer `total_cost = goldCost + making + stone` (cost basis for P&L). |

**Canonical authority:** `App\Services\PricingEngine::compute()` is the single, pure,
deterministic money authority. Mobile (`Api\Mobile\PosController`, `RepairController`) and web
both call it — *"single source of truth across web/mobile"*. Quotes are **HMAC-SHA256 signed**
over a **canonical JSON** whose **field order is frozen** (`PricingBreakdown::toCanonicalArray()`
— *"Adding fields in the future MUST append to the bottom"*). Each line already carries
`making` and `stone` as 2-decimal strings.

### 1.3 Where it is DISPLAYED / AGGREGATED / EXPORTED

- **Previews:** web POS (`pos_customer*.blade`), Quick Bill form, mobile `/pos/quote` & preview — all derive from PricingEngine output or mirror its arithmetic.
- **Invoice views / prints:** `invoices/show.blade`, `quick-bills/{show,print}.blade` render persisted `making_charges`.
- **P&L:** `ProfitReportingService` does `SUM(invoice_items.making_charges) as making` and `SUM(stone_amount)` — making is reported as **revenue component**, netted against cost (`total_cost`).
- **Returns/CN:** `RefundPolicyResolver` + `GoldValuationService` read `$line->making_charges` / `$line->stone_amount` (resolved ₹) to decide refundable vs retained per shop policy (`refund_making_charges`, `refund_stone_charges` flags on `shop_preferences`).
- **Exports:** `ExportController`, `Exports/Sheets/InvoiceItemsSheet`, CSV report exporters — emit the resolved amount.
- **Mobile APIs:** `Api/Mobile/InvoiceController` returns `making_charges`; `Api/Mobile/QuickBillController` accepts `making_charge` (flat) + returns it; `Api/PosController` accepts `making`/`making_charges` (flat, with alias fallback).

### 1.4 GST treatment

Making is **never taxed separately**. It is a component of `line_total` → `subtotal` →
`taxable = max(subtotal − discount, 0)` → `gst = round(taxable × rate/100, 2)`. Per-line GST is
apportioned by largest-remainder (`InvoiceAccountingService::apportionGstToLines`). The
`invoices_accounting_guard` DB trigger enforces
`total = subtotal + gst + wastage_charge − discount + round_off` — **it does not reference
making at all** (making lives *inside* subtotal). **Consequence:** changing *how making is
computed* cannot touch the guard, provided `line_total` stays internally consistent.

---

## 2. Hidden Assumptions Map (every "making = fixed amount" assumption)

| # | Location | Implicit assumption | Breaks if… | Severity |
|---|---|---|---|---|
| A1 | `invoice_items.making_charges` is the only making record | making is fully described by one ₹ scalar | we needed to reproduce *how* it was derived for audit and only stored the amount | LOW (we will ADD type+value, not remove amount) |
| A2 | `PricingBreakdown` canonical line = `{item_id,line_total,gst_amount,weight,rate,making,stone}` | line shape is frozen; `making` is a flat ₹ string | new fields inserted **mid-object** → every historical signed quote fails verification | **HIGH if done wrong** (mitigation: append-only) |
| A3 | `PricingEngine::recompute()` reproduces an old quote's canonical JSON for drift detection | engine output shape is stable for a given input | engine starts emitting new canonical fields → in-flight quotes (≤60 min TTL) show false drift across the deploy boundary | MEDIUM (short TTL) |
| A4 | Retailer flow: `line_total = item.selling_price`, **no gold rate at sale** | making was resolved to ₹ at *registration* and baked into selling_price | a % / per-gram making needs a rate context that the retailer **sale** path does not have | **HIGH (design constraint)** |
| A5 | `QuoteInput.making : float` (flat ₹) | callers pass a resolved amount | a caller passes a % expecting the engine to resolve it | MEDIUM (validation needed) |
| A6 | `ProfitReportingService` SUMs `making_charges` as revenue | the column is the realised making revenue | the column held a % or per-gram *rate* instead of resolved ₹ | LOW (we keep storing ₹) |
| A7 | `RefundPolicyResolver`/`GoldValuationService` deduct `$line->making_charges` as ₹ | refund math operates on resolved ₹ | column held non-₹ | LOW (we keep storing ₹) |
| A8 | Exports & mobile read `making_charges` as a ₹ number | resolved ₹ | column semantics change | LOW |
| A9 | `invoice_items.making_charges` is `NOT NULL` | a value is always present | resolution produced NULL | LOW (resolution always yields ₹, default 0) |
| A10 | Quick Bill already separates `wastage_percent` (%) from `making_charge` (₹) | making and wastage are different concepts | we conflate making% with wastage% | LOW (precedent clarifies: % making is a *new* axis) |
| A11 | Item-level making is a single value per piece | making does not depend on live rate after registration | % / per-gram making is recomputed on rate change for a *retailer in-stock* item | MEDIUM (registration-time vs sale-time resolution) |

**The two assumptions that actually constrain the design are A2 (signed canonical shape) and
A4 (retailer has no sale-time rate).** Everything else is satisfied automatically by "persist
the resolved ₹ amount."

---

## 3. Canonical Making-Charge Semantic Model

### 3.1 The triple (input → resolution → truth)

For every making charge, three facts exist. We persist all three; only the last is "money truth":

| Field | Meaning | Persisted where (new) | Mutable after finalize? |
|---|---|---|---|
| `making_charge_type` | `fixed` \| `percentage` \| `per_gram` | item + invoice_items (+ quick_bill_items) | **No** (snapshot) |
| `making_charge_value` | the raw input: ₹ (fixed), % (percentage), ₹/g (per_gram) | item + invoice_items (+ quick_bill_items) | **No** (snapshot) |
| `making_charges` (**existing**) | the **resolved ₹ amount** = invoice money truth | already persisted | **No** (already immutable) |

`making_charges` (resolved ₹) **remains the single source of truth** for all downstream math.
`type` + `value` are **audit/reproducibility metadata** and the input for *re-quoting a live
preview* — never re-resolved against a finalized invoice.

### 3.2 What is persisted vs computed vs recalculated

- **Persisted (invoice truth, immutable):** `making_charges` (resolved ₹), plus the snapshot
  `making_charge_type`, `making_charge_value`, **and the metal basis used to resolve a %/per-gram
  charge** (the gold rate and the fine/net weight already persisted on the line as `rate` /
  `weight`). Together these make a finalized line **fully reproducible** without any live lookup.
- **Computed transiently (preview / quote):** the resolved ₹ amount, recomputed from current
  rate every time a *draft* quote is generated — identical pattern to how metal value already
  tracks the live gold rate before finalization.
- **Recalculated:** **never** for a finalized invoice. A rate change tomorrow recomputes only
  *new* quotes; persisted lines keep their stamped `making_charges`.

### 3.3 Backward default

Legacy rows have `making_charge_type = NULL`. **NULL is interpreted as `fixed`** with
`making_charge_value = making_charges` (the amount IS the value). No backfill is required for
correctness; an optional metadata backfill can set `type='fixed', value=making_charges` for
analytics tidiness (safe — it does not change any amount).

---

## 4. The Three Modes — precise semantics

> All three **resolve to ₹ inside `PricingEngine`** and persist the resolved amount. The
> semantics below are the recommended canonical definitions; §4.4 lists the decisions that
> need explicit owner/CA sign-off before build.

### Mode A — Percentage (e.g. 12%)
- **Base = METAL VALUE only** (`fineWeight × goldRate`), **not** the whole item, **not**
  including stone, hallmark, or other charges. Rationale: making is labour on the metal;
  taxing/▸refunding behaviour stays clean because making remains a sibling of metal value.
- **Purity-adjusted:** yes — base uses **fine** weight (`net_metal_weight × fineWeightMultiplier`),
  consistent with how `metalValue` is already derived.
- **Before wastage charge, before discount:** making% is resolved on raw metal value; wastage
  recovery and discounts are applied at their existing stages (making is already inside
  `line_total` *before* discount in the current arithmetic — unchanged).
- **Resolution time:** at quote time for manufacturer/Quick Bill (rate known); at **registration
  time** for retailer in-stock items (see §4.4 / §5).
- `resolved₹ = round(metalValue × value/100, 2)`.

### Mode B — Per gram (e.g. ₹350/g)
- **Weight basis = NET metal weight** (`net_metal_weight`), **not gross** — gross includes stone
  and would double-count. This mirrors the metal-value weight basis.
- **Stone-adjusted:** yes (net already excludes stone weight).
- **Wastage interaction:** none — per-gram making is independent of wastage recovery (wastage
  stays its own line as today).
- `resolved₹ = round(net_metal_weight × value, 2)`.

### Mode C — Manual / fixed (current behaviour)
- `resolved₹ = value` (the typed amount). **Bit-for-bit identical to today.** This is the
  default and the migration target for all legacy data.

### 4.4 Decisions requiring explicit sign-off (do NOT assume)
1. **Percentage base:** metal-value-only (recommended) vs metal+wastage vs full item. (Affects every % invoice.)
2. **Per-gram weight:** net (recommended) vs gross.
3. **Retailer resolution time:** resolve %/per-gram at **registration** and bake into
   `selling_price` (recommended — preserves the rate-free sale path), vs convert retailer sales to
   a rate-aware flow (large, risky — **not recommended**).
4. **Rounding:** making resolves to 2-dp ₹ before entering `line_total` (recommended; matches
   `money()` canonical formatting).
5. Whether **Quick Bill** adopts the same `making_charge_type` axis it already has for wastage%.

---

## 5. Pricing-Engine Implications

- **Where pricing truth lives:** unchanged — `PricingEngine::compute()` stays the only money
  authority. The mode resolution happens **inside** the engine (manufacturer/Quick-Bill path) so
  no caller computes making independently. **No client-side financial authority** is introduced:
  mobile/web send `making_charge_type` + `making_charge_value`; the server resolves.
- **`QuoteInput` change (additive):** add `makingType: string = 'fixed'`, `makingValue: float`
  alongside the existing `making` (which becomes the *resolved* output, or is derived). Validate:
  percentage ∈ [0, cap], per_gram ≥ 0. Factories stay backward-compatible (default `fixed`,
  `makingValue = making`).
- **Canonical JSON (A2 — the sharp edge):** append new keys to the **line** object **after**
  `stone` — e.g. `making_type`, `making_value` — and **never reorder** existing keys. Historical
  quotes are **never re-serialised** (verify hashes the *stored* bytes), so they keep verifying.
  New quotes carry the new fields.
- **Drift detection (A3):** `recompute()` of an in-flight pre-deploy quote will emit the new
  canonical fields and mismatch the stored bytes → false "drift". Mitigation: (a) quotes are
  short-TTL (30–60 min); (b) gate the new canonical fields behind a feature flag so `recompute()`
  only appends them once the flag is on and only for quotes *issued* after the flag flip; OR
  (c) drain: stop honouring pre-flag quotes at the boundary. **Recommended:** feature-flag the
  canonical extension + accept that quotes issued in the final pre-deploy window must be
  re-quoted (the POS already re-quotes on expiry).
- **What the invoice snapshot must store:** **all three** — raw mode (`type`), raw input
  (`value`), and computed result (`making_charges`). Plus the already-persisted `rate` + `weight`
  on the line. This guarantees **historical reproducibility**: yesterday's invoice can be
  recomputed from its own stored inputs and will reproduce its own `making_charges` regardless of
  today's rate.

---

## 6. GST Implications

- **Making remains taxable**, exactly as today — it stays inside `line_total → taxable`. No new
  GST treatment, no new HSN/SAC, no separate making tax line.
- **Mode does not change the GST breakdown** because GST is computed on `line_total`, and
  `line_total` is identical whether making arrived as fixed, %, or per-gram — only the *derivation*
  differs, not the resulting amount.
- **Accounting guard:** untouched (`total = subtotal + gst + wastage − discount + round_off`;
  making not referenced).
- **Discount interaction:** unchanged — discount applies after `subtotal` (which includes making);
  percentage making is resolved on metal value **before** discount, so the discount still reduces
  the whole taxable base uniformly.
- **Returns / CN:** unchanged — `RefundPolicyResolver` deducts the persisted resolved
  `making_charges`. A %-derived making of ₹X refunds/retains identically to a fixed ₹X.
  `refund_making_charges` policy flag semantics are mode-agnostic.
- **Scheme / store-credit:** no interaction — those settle against `final_total`, downstream of
  making resolution.

---

## 7. Reporting Implications

| Report | Reads | Impact | Needs evolution before rollout? |
|---|---|---|---|
| **P&L** (`ProfitReportingService`) | `SUM(invoice_items.making_charges)` | none — sums resolved ₹ | No |
| **GST** (`GstReportingService`, `TaxService`) | line totals / gst | none — making inside line_total | No |
| **Sales / operator analytics** | invoice totals, user_id | none | No |
| **Making-charge revenue** | `making_charges` | works; **optional enhancement**: group by `making_charge_type` for "% vs fixed vs per-gram" mix analytics | Optional, post-rollout |
| **Karigar profitability** | karigar lines / job grams | none (separate making columns) | No |
| **Exports / CA reports** | resolved amounts | none; **optional**: add `Making Type` column to item/invoice-item exports | Optional |
| **Reconciliation** (`reports:validate`, returns/vault) | resolved amounts, guard identities | none | No |

**Conclusion (mc8):** reports do **not** require semantic evolution before rollout. The only
report changes are **optional, additive analytics** (mode mix), safe to ship after the core lands.

---

## 8. Mobile Parity Implications

- **Authority:** mobile POS/repair already call `PricingEngine::compute()` — parity is preserved
  **by reuse**, not duplication. Resolving modes server-side automatically keeps web/mobile
  identical.
- **Transport contracts (additive):** `Api/PosController` and `Api/Mobile/PosController` accept
  `making_charge_type` + `making_charge_value` (keep `making`/`making_charges` as the
  fixed-mode alias for old clients). `Api/Mobile/QuickBillController` mirrors its existing
  `making_charge` with an optional `making_charge_type`.
- **Preview:** `/pos/quote` returns the resolved making in `lines[].making` (unchanged key) plus
  new `lines[].making_type`/`making_value` (additive) — old apps ignore unknown keys.
- **Offline assumptions:** an offline client must **not** resolve %/per-gram itself (no
  client-side financial authority). It may *show* an estimate but the server-resolved amount is
  authoritative on sync. Document this in the mobile contract.
- **Read endpoints** (`Api/Mobile/InvoiceController`) keep returning `making_charges`; add
  `making_charge_type` for display ("12% making") — additive, non-breaking.

---

## 9. UX Implications

- **Mode selector** at the making-charge input (item registration form + manufacturer POS +
  Quick Bill): a small `type` dropdown (`Fixed ₹ / % of metal / ₹ per gram`) next to the value
  field. Label the value field dynamically (`₹` / `%` / `₹/g`).
- **Default:** `Fixed` (zero behaviour change unless the operator opts in). Per-shop default
  can later live in `shop_preferences` (e.g. `default_making_charge_type`).
- **Always show the resolved ₹** next to the input in real time ("12% of ₹40,000 metal =
  **₹4,800**"), and persist that explanation into the line so the invoice/print can show
  *why* (mc10: operators must always understand why a making charge became that amount). The
  preview should render: `Making: ₹4,800 (12% of metal)`.
- **Item-level vs invoice-level:** making is **item/line-level** (matches current model). No
  invoice-level making mode — avoids ambiguity on mixed-mode multi-line bills.
- **Editable:** operator may switch mode/value on a *draft* quote; once finalized it is frozen
  (ImmutableLedger). Retailer in-stock items resolve at registration; changing the rate later
  prompts a re-price of the item (existing retailer reprice flow), not a silent sale-time change.

---

## 10. Migration / Backward Compatibility

- **Existing invoices:** untouched. `making_charge_type` NULL ⇒ treated as `fixed`; `making_charges`
  remains the truth. Fully reproducible (amount already stored).
- **Existing APIs:** `making`/`making_charges` flat inputs keep meaning "fixed". New optional
  `making_charge_type`/`making_charge_value` default to fixed. No breaking change.
- **Old reports / exports:** unchanged (read resolved ₹).
- **Old signed quotes:** verify against their stored bytes — unaffected. New canonical fields are
  append-only and feature-flagged.
- **Schema:** **additive nullable columns only** — `items.making_charge_type`,
  `items.making_charge_value`; `invoice_items.making_charge_type`, `invoice_items.making_charge_value`;
  (optional) `quick_bill_items.making_charge_type`, `quick_bill_items.making_charge_value`;
  (optional) `shop_preferences.default_making_charge_type`. **No NOT-NULL, no drops, no backfill
  required for correctness.** Mirrors the additive-nullable discipline used for `invoices.user_id`.
- **Accounting truth:** never recomputed; the guard trigger and ImmutableLedger are untouched.

---

## 11. Rollout Phases

| Phase | Scope | Risk | Gate |
|---|---|---|---|
| **MC-0** | This audit + owner/CA sign-off on §4.4 decisions | none | sign-off |
| **MC-1** | Additive nullable schema (items + invoice_items + optional quick_bill_items). No reads, no writes yet. | LOW | migration green, `reports:validate` + `returns:validate` green |
| **MC-2** | `QuoteInput`/`PricingEngine` resolve modes server-side; **fixed-only behaviour preserved**; canonical line gains append-only `making_type`/`making_value` **behind a feature flag**; golden test that fixed-mode canonical JSON is byte-identical to pre-change | MEDIUM (A2/A3) | signed-quote golden tests + drift test pass |
| **MC-3** | Persist `type`+`value` snapshots in `SalesService`/`RetailerSalesService`/`QuickBillService`; resolved ₹ unchanged | LOW | parity tests: resolved amounts identical to today for fixed mode |
| **MC-4** | Item-registration UI + manufacturer POS + Quick Bill UI mode selector; live "why this amount" preview | LOW | UX review; plain-English per `simple-english-ui` |
| **MC-5** | Retailer registration-time resolution (%/per-gram → baked selling_price) per §4.4 decision 3 | MEDIUM (A4) | reprice-flow tests |
| **MC-6** | Mobile transport additive fields (web/mobile parity preserved by engine reuse) | LOW | mobile contract tests |
| **MC-7** | Optional analytics: making-mode mix in reports/exports; optional metadata backfill (`type='fixed'`) | LOW | post-pilot |

Each phase is independently shippable and reversible (drop the nullable column / revert the
feature flag → prior behaviour returns).

---

## 12. Backward-Compatibility Guarantees (contract)

1. A finalized invoice's `making_charges`, `line_total`, `gst`, and `total` are **never**
   altered by this work.
2. A historical signed quote continues to verify (stored bytes unchanged; new canonical fields
   append-only + flagged).
3. Fixed-mode behaviour is **bit-identical** to today (golden-tested).
4. All existing APIs accept their current payloads unchanged; new fields are optional.
5. The accounting-guard trigger and ImmutableLedger are untouched.
6. P&L, GST, returns/CN, exports, reconciliation produce identical numbers for pre-existing data.

---

## 13. Risk Classification

| Risk | Likelihood | Impact | Mitigation | Residual |
|---|---|---|---|---|
| **A2** canonical JSON shape break | Low (if disciplined) | HIGH (all quotes fail verify) | append-only keys; golden byte-test; never reorder | LOW |
| **A3** in-flight quote drift across deploy | Medium | MEDIUM (re-quote needed) | feature-flag canonical extension; short TTL; re-quote on expiry | LOW |
| **A4** retailer has no sale-time rate | Certain (design fact) | HIGH if ignored | resolve %/per-gram at **registration**, bake into selling_price; do NOT make retailer sale rate-aware | LOW |
| Mode misread as amount in a report/export | Low | MEDIUM | resolved ₹ stays the only summed column; `value` is metadata | LOW |
| Operator confusion (why this amount) | Medium | LOW | live "X% of metal = ₹Y" preview + print breakdown | LOW |
| Mobile offline self-resolves making | Low | MEDIUM | server is sole authority; offline shows estimate only | LOW |
| NOT-NULL violation on `making_charges` | Low | LOW | resolution always yields ₹ (default 0) | NEGLIGIBLE |

---

## 14. Constraints Honoured

- **No implementation performed** — design only.
- **No pricing-semantics mutation**, no duplicated pricing logic — all resolution stays inside
  the canonical `PricingEngine`.
- **No client-side financial authority** — modes are resolved server-side; mobile/offline send
  inputs, never authoritative amounts.
- Preserves: canonical pricing engine, invoice immutability, accounting truth, GST integrity,
  reconciliation, and historical reproducibility.

---

## 15. Final Goal (restated, now grounded)

A future-proof, accounting-safe, pricing-authoritative making-charge system supporting
**percentage**, **per-gram**, and **manual** modes — achieved by treating mode + raw value as
**additive input metadata** that the canonical engine **resolves to the same rupee amount the
system already trusts**, so the hardened financial core (immutability, GST, guard triggers,
reconciliation, reproducibility, web/mobile parity) is never destabilised.

> **Next step is NOT code.** It is sign-off on the §4.4 semantic decisions and the §11 phasing.
> Only after that does MC-1 (additive schema) begin.
