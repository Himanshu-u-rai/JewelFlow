# Material Identity — Contributor Guide

> Short, practical guide for anyone touching material/metal/stone code. The full reasoning is in `material-identity-audit.md`; the rollout is in `material-identity-alignment-plan.md`. **Read this before adding any metal- or purity-related code.**

## The one rule that prevents corruption

**Fine weight comes ONLY from `MetalRegistry::fineWeightMultiplier()` / `MetalRegistry::fineWeight()`. Never write `purity / 24` or `purity / 1000` inline in new code.**

That authority returns `null` for any metal whose purity is not accounting truth (platinum, copper). If you inline the division, you can silently turn a platinum hallmark grade (e.g. `95`) into fake vault grams — which corrupts accounting and reinterprets history. Don't.

## "Purity" is four different things

There is no universal purity concept. Each metal belongs to exactly one identity class (`MetalRegistry::identityClass($metal)`):

| Class | Metals | What "purity" means | Drives money? |
|---|---|---|---|
| `purity_accounting` (A) | gold, silver | Fine-weight multiplier (karat / millesimal) | **Yes** — vault, exchange, melt, reconciliation, rate |
| `purity_spec` (B) | platinum | Hallmark grade (Pt950/Pt900) — a stamp | No — piece-priced |
| `manual_grade` (D) | copper | Nothing (optional coarse type) | No — piece-priced |
| `attribute_value` (C) | diamonds/stones | **Not a metal.** Rupee value + 4Cs | No — per-piece value |

## Why platinum is not "gold-lite"

In real Indian shops platinum is a fixed-price luxury product, not a commodity. There is no daily platinum rate; the price is the supplier tag + markup. Pt950 is a hallmark **specification** you show the customer — it is NOT a multiplier. So:
- Platinum item creation is piece-priced (operator types the price).
- The purity field for platinum is an optional "Hallmark grade" selector that never drives price.
- Platinum cannot be added as a vault lot and produces no purity-derived fine weight (enforced by the fine-weight authority + `accountingTruthMetals()`).

## Why stones never have purity

A diamond has no "purity %." Its identity is carat/clarity/color/cut/certificate (advanced) or, for pilot, just a **rupee `stone_amount`**. Carat is *weight*, not purity. Stones are not metals — `MetalRegistry::identityClass('diamond')` throws on purpose. Never add a purity field to a stone; never route a stone through the metal/fine-weight machinery.

## Capability cheat-sheet (read behavior from these, never hardcode metal names)

- `identityClass($metal)` — the discriminator.
- `purityIsAccountingTruth($metal)` — true only for gold/silver.
- `purityIsSpecification($metal)` — true only for platinum.
- `puritySelectorMode($metal)` — `mandatory` | `lightweight` | `hidden` (drives the item form).
- `purityLabel($metal)` — "Karat (K)" / "Fineness" / "Hallmark grade" / "".
- `accountingTruthMetals()` — `['gold','silver']`, capability-driven (use instead of `in:gold,silver`).
- `fineWeightMultiplier($metal,$purity)` / `fineWeight($metal,$net,$purity)` — the ONLY fine-weight source; null for non-accounting metals.

## When you change material behavior

Your journal entry (and PR) MUST state:
1. Which identity class(es) it affects.
2. Any capability added/changed.
3. The operator-facing implication in plain English.
4. Invariant impact (should be "none" unless you touched accounting).

## Hard don'ts (anti-ERP)

No generic material/attribute engine, no alloy decomposition, no gemological grading engine, no universal purity abstraction, no schema redesign of `items.purity` (the column meaning is enforced by the fine-weight authority, not by splitting storage). Model how a shop owner thinks, not material science.

## See also
- `material-identity-audit.md` — the why (approved truth).
- `material-identity-alignment-plan.md` — the phased plan + Claude/MinMax task split.
- `material-behavior-audit.md` — operational behavior per material.
- `docs/journals/material-ux-alignment-journal.md` — implementation log (entries [10]–[14] are the identity phases).
