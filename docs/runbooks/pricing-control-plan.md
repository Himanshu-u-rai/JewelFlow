# Pricing-Control Plan

> **Status:** APPROVED 2026-05-28 (full approval). R1 (vocabulary lock-in) shipped. **R2 awaits explicit go-ahead.**
> **Authority:** Material Identity Audit + Live-Price Verification Audit (this repo's `docs/runbooks/`).
> **Nature:** operator pricing ergonomics aligned with material truth — NOT a redesign, NOT a universal commodity engine, NOT a return to the material-identity philosophy debate.
>
> **Hard rule:** the three material pricing classes (A/B/C) must NEVER collapse back into one engine. Each class has its own storage, service, vocabulary, and UI surface. The build fails if they cross.

---

## 0. Goal & non-goals

**Goal.** Operators choose, per material, what they actually maintain daily — gold/silver as accounting truth, platinum/copper as optional manual reference memo, stones never. The system stays honest about what each price means.

**Non-goals (forbidden).** Universal market-price engine. Commodity-trading abstractions. Generalised valuation matrix. Reinterpreting reference prices as accounting rates. New tier classes. New constitutional articles. Schema redesign of `shop_daily_metal_rates`.

**Pilot invariant.** A gold-and-silver shop sees **zero behaviour change** from this entire plan.

---

## 1. The three pricing classes (the model that must not collapse)

| Class | Materials | What the price IS | Who maintains it | Stored where | Drives money? |
|---|---|---|---|---|---|
| **A — Accounting rate** | gold, silver | Daily fine-weight rate × purity → vault, exchange, melt, reconciliation, repricing, GST | Mandatory daily by owner | `shop_daily_metal_rates` (+ Phase 1 Stage A entries) | **Yes** — directly |
| **B — Reference price (manual memo)** | platinum, copper | A *piece-price hint* the operator records "what I am selling platinum at this week" | Optional, irregular, owner-chosen | **NEW** `shop_metal_reference_prices` (additive, separate from class A) | **NO** — display/hint only; never enters pricing, vault, reprice, or reconciliation |
| **C — Value-only** | diamonds, stones, gems | Per-piece rupee value entered per item (`stone_amount`) | n/a | `invoice_items.stone_amount` / `stone_components` | No |

**Critical:** B is a memo, not a rate. It exists so an operator can record "Pt950 is around ₹3,200/g this month" for their own reference and for showing a hint at item-creation time. It never becomes a fine-weight multiplier (already guarded by `MetalRegistry::fineWeightMultiplier()` returning null), never appears in reprice loops, never feeds vault math, and never sits in the same table as accounting rates. **Distinct storage, distinct vocabulary, distinct service.**

---

## 2. Daily-update participation model

Who maintains what daily — anchored in real shop behaviour:

| Material | Update cadence in real shops | System policy |
|---|---|---|
| **Gold** | Every morning, sometimes twice | **Required for the day's operations** (existing `assertRetailerPricingReady`) |
| **Silver** | Every morning, lower attention | **Required**, same engine |
| **Platinum** | Weekly, monthly, or never (supplier-tag piece-priced in most Indian shops) | **Optional**; never blocks item creation (piece-price flow is already the default); the reference price has no "today required" check |
| **Copper** | Never (piece-priced) | **Optional**; same as platinum |
| **Stones** | n/a — per-piece value | **No daily UI at all** |

**The "missing today's reference price" state is not an error for B-class.** It is the normal state. Compare with class A, where missing today's gold rate blocks item creation — that's correct for A and stays as-is.

---

## 3. Historical tracking semantics (the drift trap)

This is where developers most easily reinterpret a memo as accounting truth. The plan separates the two by **storage, name, and access path**:

| Concept | Class A | Class B |
|---|---|---|
| Storage | `shop_daily_metal_rates` (one row per business date) + `shop_daily_metal_rate_entries` (per-purity Stage A) | `shop_metal_reference_prices` (one row per *update event*, append-only) |
| What a row means | "On 2026-05-28, today's gold 24K rate is ₹X — used for ALL pricing/reprice/vault math that day" | "On 2026-05-28 the owner noted platinum reference is ~₹X — for memo and display hint only" |
| Service | `ShopPricingService` (rate engine; immutable historical truth) | **NEW** `ReferencePriceService` (read/write to the new table only; cannot call into ShopPricingService for rates) |
| Reports | "Daily Rates History" (audit-grade) | "Reference Prices — last updated" (memo, with timestamp + author) |
| Audit interpretation | Accounting truth; reconciled, never altered | Operator note; freely added, no reconciliation meaning |

**Hard rule:** `shop_metal_reference_prices` MUST NOT carry any column named `rate_per_gram`. Use `reference_price` and `noted_at`. Different vocabulary structurally blocks "promote to rate" drift.

---

## 4. UI / UX

The visible product reflects the model:

- **Settings → Pricing → Daily Rates tab.** Exactly the current screen — gold + silver inputs, daily. Unchanged. No platinum/copper/stone fields appear here, ever.
- **Settings → Materials tab.** Already exists. When platinum or copper is toggled on, a *small* "Reference price" card appears for that metal:
  > **Platinum reference:** ₹\_\_\_\_ / g  ·  Last noted: 17 May 2026 by Owner  ·  *Used as a hint only; platinum items are sold at a fixed price per piece.*
  
  One-input form, save records a new `shop_metal_reference_prices` row (append-only, with `noted_at` + `noted_by_user_id` + a freeform reason). Empty is normal.
- **Item creation form.** Already piece-price for platinum/copper (Stage 2). The reference price, *if any has been noted*, appears as a small grey hint: "*Recent reference: ₹3,200/g (noted 17 May).*" It is **not** auto-filled into the selling-price field — the operator types the actual price they're charging today. The hint is a memory aid, not a calculation input.
- **Stones.** No rate or reference UI anywhere. The only UI is the `stone_amount` field on the item line.

What the operator should *feel*: gold/silver have a rate they update; platinum/copper have an optional note they can update when they remember; stones are priced per piece. Not a commodity terminal.

---

## 5. Pricing engine safety (the boundaries the plan must preserve)

| Invariant | Preserved by |
|---|---|
| Fine-weight is gold/silver only | `MetalRegistry::fineWeightMultiplier()` returns null for any other metal — already shipped, locked by `FineWeightAuthorityExclusivityTest` |
| Live-rate read/write is gold/silver only | `shop_daily_metal_rates` schema (no other columns) + `ShopPricingService::normalizeMetalType` hard-restricting — already shipped |
| Reference prices never enter fine-weight | `ReferencePriceService` never calls `ShopPricingService::resolvedRateForToday`/`fineWeight`/`fineWeightMultiplier`. Asserted by an architecture test. |
| Auto-reprice (`RepriceRetailerInventoryJob`) never consumes reference prices | The reprice job calls `computeRetailerCostPayload`, which short-circuits to piece-price for class B/D. Reference price is never read in that path. Asserted by test. |
| Vault / reconciliation never reads reference prices | `BullionVaultController`, `vault:reconcile`, `karigar:reconcile`, `BullionVaultService::vaultBalances` are gold/silver-only and never join to `shop_metal_reference_prices`. Asserted by test. |

The structural protection is the same one that already prevents platinum from becoming gold-lite: the *PHP authority returns null*. The plan extends this discipline to reference prices: **`ReferencePriceService` lives in a different file, has different method names (`recordReference`, `latestReference`), and is forbidden by test to be imported into accounting/pricing/vault services.**

---

## 6. Historical-meaning protection (anti-drift, permanent)

**Naming strategy** (the single most important guard):

| Forbidden | Required |
|---|---|
| `rate_per_gram` on reference table | `reference_price` |
| `business_date` on reference table | `noted_at` (timestamp, not a business-date concept) |
| `metal_rate` in reference service/method names | `reference_price`, `note`, `memo` |
| Foreign keys between reference and rate tables | No FKs between the two storage families |
| Joining reference + rate tables in a report | Reports list them as two separate sections labeled differently |

**Structural enforcement:**
- A test scans `ReferencePriceService` and `shop_metal_reference_prices`-related code for the words `rate_per_gram`, `resolvedRateForToday`, `RepriceRetailerInventoryJob`, `MetalRate::`, `shop_daily_metal_rate` — finding any of those in the reference path fails the build.
- A test asserts that `computeRetailerCostPayload`, `BullionVaultService::vaultBalances`, and `RepriceRetailerInventoryJob` source code does NOT contain any of `ReferencePriceService`, `shop_metal_reference_prices`, `latestReference`.
- A test asserts `materials:audit` still scans the reference module (recursive — already fixed) and the new files contain no hardcoded metal literals.

**Documentation rule (permanent):** any future change touching reference prices must state in its journal entry: (a) which class (A/B/C) it touches, (b) that it does NOT cross into the other classes, (c) operator-facing implication. A reference-price change with no class-A/B separation note is incomplete.

---

## 7. Reporting & operator expectations

| Surface | Class A (gold/silver) | Class B (platinum/copper) | Class C (stones) |
|---|---|---|---|
| Daily Rates dashboard | Full primary surface | Absent | Absent |
| Daily Rates **history** report | Per-purity rate history, audit-grade | Absent from this report | Absent |
| **Reference Prices** screen (NEW) | Absent | "Last noted reference, by metal, with timestamp + author + note" — append-only timeline | Absent |
| PnL / Closing | Uses class-A rates for valuation | Reference prices NEVER feed PnL/Closing; platinum revenue is the selling_price column on items | Stone amount is per-piece value |
| Vault summary | Gold/silver primary lines | "Other materials" lot count + grams only (Stage 4 design — no rate-derived valuation) | Stones never in vault |

**The "Reference Prices" screen is the single new reporting surface in this plan.** It is operator memory, not accounting history. It does not feed any downstream report.

---

## 8. Operational realism check

Do operators actually maintain daily platinum/copper market books? Per the operational audit: **no.** Most shops set platinum prices from a supplier invoice and don't track market changes day-to-day. Copper isn't a market product in mainstream retail at all.

**The plan respects this.** The reference price is *optional, irregular, with no "missing today" alarm*. Shops that never touch it lose nothing. Shops that occasionally update it gain a memo. The system does not simulate a fake daily-platinum-rate workflow.

---

## 9. Anti-ERP boundary (permanent)

Permanently forbidden, applies to every phase:
- Auto-fetched platinum / copper / stone market prices from any feed
- "Convert reference price to a daily rate" promotion path of any kind
- Per-purity reference prices (a reference is per-metal, not per-Pt950 vs Pt900)
- Aggregating reference prices into PnL, GST, vault valuation, reconciliation, or auto-reprice
- Cross-shop reference-price sharing or marketplace
- AI suggestions for reference price
- Multi-currency reference prices

JewelFlow is a jewelry business OS. Reference prices are a memo field. Period.

---

## 10. Phased implementation (no code yet — sequence + scope only)

Each phase one PR + journal entry. Pilot invariant verified at every phase.

| Phase | Title | Scope (what counts as "done") |
|---|---|---|
| **R1** | Vocabulary lock-in | Three docs published: this plan, a one-page contributor guide (`material-pricing-classes.md` — names + table to consult), and a journal vocabulary note. Zero code. |
| **R2** | Reference-price storage | Additive migration adds `shop_metal_reference_prices(id, shop_id, metal_type, reference_price, noted_at, noted_by_user_id, note)` with FK guards and an append-only Eloquent trait. No new model methods that read/write rate tables. `ReferencePriceService::recordReference`/`latestReference` only. |
| **R3** | Settings UI surface | "Reference price" card on Settings → Materials per opted-in class-B metal. Save writes a new row. Empty state is normal. No daily-rate UI touched. |
| **R4** | Item-creation hint | Item form shows the most recent reference as a grey hint when the chosen metal is class B and a reference exists. Hint is display-only; selling_price is still operator-typed. |
| **R5** | Reference-prices history report | A dedicated screen listing "Reference price — Platinum — ₹X / g — 17 May — Owner — note". Append-only timeline, per metal. Never joined to daily-rates history. |
| **R6** | Anti-drift tests | Architecture tests asserting: (i) reference service does NOT import rate engine, (ii) pricing/vault/reprice paths do NOT import reference service, (iii) forbidden field names are absent from the reference table, (iv) `materials:audit` stays clean. |
| **R7** | Documentation + contributor guide | Add to `material-identity.md` a short "and reference prices" section pointing at this plan + the §6 forbidden names. |

Rollout strictly in order R1 → R7. Stop at any phase whose invariant verification fails.

### Per-phase invariant gate
- `php artisan test tests/Feature/Material/` — green
- `php artisan test tests/Feature/ConstitutionalInvariantsTest.php` — green
- `php artisan returns:validate` — green
- `php artisan materials:audit` — clean (recursive scan)
- `php artisan vault:reconcile` — exit 0
- Pilot smoke (gold/silver shop): unchanged

---

## 11. Verification checklist (when the plan is fully implemented)

1. `ReferencePriceService` exists in its own file; greps for `ShopPricingService`, `MetalRate::`, `resolvedRateForToday`, `fineWeightMultiplier` inside it return zero.
2. `ShopPricingService`, `BullionVaultService`, `RepriceRetailerInventoryJob`, and `computeRetailerCostPayload` source code contains zero references to `ReferencePriceService`, `shop_metal_reference_prices`, or `latestReference`.
3. `shop_metal_reference_prices` schema has no column named `rate_per_gram`, `business_date`, or `resolved_rate_per_gram`.
4. PnL and Closing reports do not load any row from `shop_metal_reference_prices`.
5. Vault reconciliation produces identical output before and after Reference Prices exist (zero impact).
6. Auto-reprice job, run with platinum items having reference prices on file, computes selling_price exactly as the operator entered — no rate-derived recalculation.
7. `materials:audit` is recursive-clean.
8. Anti-drift tests: green and named after specific drift vectors (`reference_price_never_in_accounting_pricing`, `reference_service_never_imports_rate_engine`, etc.).
9. A gold-and-silver shop sees exactly today's UI — zero behaviour change.

---

## 12. One-paragraph summary

JewelFlow already enforces "gold/silver are accounting truth; platinum/copper are piece-priced; stones are per-piece value" at the identity layer. This plan extends that discipline to **pricing controls** by giving platinum/copper an optional, append-only **reference price** that is structurally a memo — different table, different field names, different service, different reporting surface — and refuses by test to enter any accounting/pricing/vault/reprice path. Stones never enter rate logic. Operators get the daily morning workflow they already know for gold and silver, an optional "note the platinum price this week" affordance for opted-in Tier-2, and nothing for stones. The build fails if anyone tries to merge the three classes back into one engine.
