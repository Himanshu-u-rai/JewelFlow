# Material Pricing Classes — Contributor Guide

> **Read this before touching any pricing, rate, vault, reprice, or material-related code.** It is the one-page rule of the three pricing classes. The full reasoning is in `pricing-control-plan.md`; the why is in `material-identity-audit.md`.

## The three classes (memorise these)

| Class | Materials | What the price IS | Storage | Drives money? |
|---|---|---|---|---|
| **A — Accounting rate** | gold, silver | Daily fine-weight rate × purity | `shop_daily_metal_rates` + `shop_daily_metal_rate_entries` | **Yes** — vault, reprice, GST, reconciliation |
| **B — Reference price (memo)** | platinum, copper | Operator note — "what I'm selling at this week" | `shop_metal_reference_prices` (NEW, R2 onward) | **No** — display hint only |
| **C — Value-only** | diamonds, stones | Per-piece rupee value | `invoice_items.stone_amount`, `stone_components` | No — per-piece |

`MetalRegistry::identityClass($metal)` is the discriminator at the metal layer. Stones are NOT metals (`identityClass('diamond')` throws by design).

## The one rule that protects everything

**Class A and Class B never share storage, vocabulary, service, or report.** The build fails if they cross.

## Naming — required vs forbidden

| Concept | Class A (accounting) | Class B (memo) |
|---|---|---|
| Column for the figure | `rate_per_gram`, `gold_24k_rate_per_gram`, `silver_999_rate_per_gram` | `reference_price` |
| Time field | `business_date` | `noted_at` |
| Service | `ShopPricingService` | `ReferencePriceService` |
| Methods | `resolvedRateForToday`, `saveTodayBaseRates`, `fineWeightMultiplier` | `recordReference`, `latestReference` |
| Models | `ShopDailyMetalRate`, `MetalRate` | (R2 onward) `ShopMetalReferencePrice` |
| Report screen | "Daily Rates History" | "Reference Prices — last noted" |

**Forbidden combinations** (each fails an architecture test):
- `rate_per_gram` on the reference table
- `business_date` on the reference table
- Any FK between the two storage families
- `ReferencePriceService` importing `ShopPricingService`, `MetalRate`, `resolvedRateForToday`, `RepriceRetailerInventoryJob`, `fineWeightMultiplier`, `shop_daily_metal_rate*`
- `ShopPricingService`, `BullionVaultService`, `RepriceRetailerInventoryJob`, `computeRetailerCostPayload` mentioning `ReferencePriceService`, `shop_metal_reference_prices`, `latestReference`
- A report query joining `shop_daily_metal_rates` and `shop_metal_reference_prices` (list them as two separate sections instead)

## Where each class may appear in code

| Path | Class A allowed | Class B allowed | Class C allowed |
|---|---|---|---|
| Item creation pricing (`computeRetailerCostPayload`) | ✓ rate-derived branch | ✓ piece-price branch — **reads no reference price** | n/a (stones flow via `stone_amount` field) |
| Vault, reconciliation, reprice | ✓ | ✗ — never | ✗ — never |
| GST, PnL, Closing | ✓ | ✗ — reference prices do not feed reports | per-piece `stone_amount` only |
| Settings → Daily Rates tab | ✓ | ✗ | ✗ |
| Settings → Materials → Reference Price card | ✗ | ✓ | ✗ |
| Item form purity selector | mandatory | lightweight (`Hallmark grade`) | n/a |
| Item form stone field | n/a | n/a | `stone_amount` only |

## When you write new code

1. Ask: which class does this touch?
2. If it touches class A and class B both, you are almost certainly drifting — stop and re-read the plan.
3. If you need to compute fine weight, use **only** `MetalRegistry::fineWeightMultiplier()` or `fineWeight()`. Never inline `purity / 24` or `purity / 1000`. (Enforced by `FineWeightAuthorityExclusivityTest`.)
4. If you need a rate, use **only** `ShopPricingService::resolvedRateForToday()` — gold/silver only by design.
5. If you need a reference, use **only** (R2 onward) `ReferencePriceService::latestReference()` — display hint only.
6. Stones never enter rate or fine-weight code at all.

## When you write a journal entry that touches any pricing/material code

State explicitly:
- Which class(es) it touches (A / B / C)
- That it does not cross into the other classes
- Operator-facing implication in plain English
- Invariant impact (should be "none" unless you touched accounting)

A material/pricing change without a class declaration is incomplete.

## What this guide does NOT cover

- The why (see `material-identity-audit.md` and `material-behavior-audit.md`).
- The how-to-implement (see `pricing-control-plan.md` R1–R7).
- General contributor onboarding (see `material-identity.md`).

## TL;DR

Gold/silver are accounting rates. Platinum/copper are memo references. Stones are per-piece values. Never let them share storage, vocabulary, service, or report.
