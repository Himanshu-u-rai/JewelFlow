# Phase 3 — Optional Material Expansion — Governance Doc

> **Status: ACTIVATED 2026-08-01 (governance tooling shipped).** No new metals admitted. The activation milestone is the **enforcement infrastructure** that makes future admissions cheap, safe, and automatically auditable.
>
> Tooling shipped:
>   - `php artisan materials:audit` — read-only audit of all 8 governance invariants (tier consistency, CHECK constraints, MetalRegistry capabilities, per-metal-class/table bans, hardcoded-literal scan, shop_enabled_metals consistency). Scheduled weekly.
>   - `php artisan materials:propose-metal {code} --tier=N --rationale="…"` — non-destructive proposal scaffolder. Runs governance checks, prints required migration/test/config edits on pass, refuses on any of the 6 programmatic objections.
>   - 5 new Phase 3 invariants in `ConstitutionalInvariantsTest` (no per-metal services, no per-metal tables, config/DB consistency, audit command runs clean, propose-metal rejects invalid input).
>
> When a real shop requests a specific new metal, the operator runs `propose-metal`, applies the printed scaffold, and the audit/test suite verifies the addition is constitutionally clean.

This document governs the addition of any new metal beyond gold, silver, platinum, copper (the Phase 1 supported set).

## Admission criteria (ALL must be met)

To add a new metal, the following MUST be demonstrably true:

1. **Real shop demand:** at least one pilot shop has been transacting in this material for **6+ months** using a documented workaround (manual entries, separate spreadsheet, etc.).
2. **Tier classification proposal:** the new metal is proposed as Tier 1, Tier 2, or Tier 3 with a written rationale covering each capability flag (`isLiveRateEligible`, `isAutoRepricedEligible`, `isDhiranEligible`, `isExchangePaymentEligible`).
3. **No new abstractions:** the addition must be implementable via:
   - One config change in `config/materials.php` (`tier_1` or `tier_2` list)
   - One `MetalRegistry::allSupportedMetals` widening (implicit via config)
   - One DB migration widening the CHECK constraints on `items`, `metal_lots`, `products`
   - Per-shop opt-in via `shop_enabled_metals` (operator action, not code)
4. **No new schema columns** for the new metal. Per-metal columns are forbidden — they reintroduce the gold/silver-style hardcoding that Phase 1 explicitly removed.
5. **No new service class** specific to this metal (no `PalladiumValuationService` etc.).
6. **No new table named after this metal** (no `palladium_lots`).
7. **No new market data integration** for this metal.
8. **No new pricing formula** that isn't expressible through the existing rate × weight × purity model.
9. **No operational flow** that bypasses `MetalRegistry`.
10. **Founder-signed constitutional review** confirming the addition fits Articles XIII–XV without amendment.

If any one criterion is unmet, the proposal is rejected.

## Survivability rule

> The system's core accounting invariants must remain unchanged regardless of how many metals are added.

Adding 50 metals over 10 years must not require any change to:
- `invoice_items` schema
- `metal_movements` schema (post-Phase-0)
- `metal_lots` schema
- DB triggers (existing 22)
- `ImmutableLedger` trait
- Constitutional articles I–XV

If a proposed metal requires changes to any of the above, **the proposal is rejected**. The metal cannot be supported in the current architecture without redesign — and redesign is a separate constitutional event, not a Phase 3 admission.

## Implementation budget

A constitutionally-clean metal addition takes **less than one engineering day**:
- 30 minutes: `config/materials.php` edit + tier classification entry
- 30 minutes: migration to widen the three CHECK constraints
- 1 hour: extending `ConstitutionalInvariantsTest` with capability assertions for the new metal
- 1 hour: documentation update (CONSTITUTION.md note + this governance doc)
- 30 minutes: operator opt-in template SQL

If the implementation takes longer, the metal is too special-cased and the proposal is rejected.

## Evaluation process

1. **Demand validation:** documented shop usage, transaction count, rupee volume
2. **Tier proposal:** which tier, with capability-flag rationale
3. **Capability matrix review:** which `MetalRegistry::is*Eligible` flags apply, with reasoning
4. **Constitutional review:** does this addition fit Articles XIII–XV?
5. **Founder sign-off** with 72-hour cooling-off
6. **Implementation:** typically 1 migration + 1 config update + 1 test addition + 1 doc update

## Anti-ERP boundaries (permanent)

Permanently rejected proposals, regardless of business pressure:

- **Multi-metal alloy decomposition** ("show 0.6g gold + 0.4g silver in this 1g alloy") — alloys are tracked as one metal_type per the shop's choice; per-element breakdown is ERP fantasy.
- **Per-metal custom service classes** (`PalladiumValuationService`) — every metal flows through the existing pricing/valuation/reconciliation services.
- **Per-metal custom tables** (`palladium_lots`) — `metal_lots` holds all metals, distinguished by `metal_type`.
- **Live commodity-exchange integrations** — JewelFlow is a shop ledger, not a trading platform.

## Until admission

- No code work is permitted on Phase 3 metals (anything beyond gold, silver, platinum, copper)
- No reference to those metals in service code, controller code, migrations, or tests
- This document is the boundary; crossing it requires founder sign-off

**The default position is: Phase 3 does not exist as work.**

## Currently supported metal set (Phase 1)

| Tier | Metals | Capability summary |
|---|---|---|
| Tier 1 | gold, silver | Full support: every flow without restriction |
| Tier 2 | platinum, copper | Limited: inventory + invoicing + reconciliation + reporting; NO dhiran, NO exchange payment, NO auto-reprice, NO live-rate auto-fetch |
| Tier 3 | (everything else) | Blocked at three layers: validator (422), service (`MetalRegistry::assertSupported`), DB CHECK constraint |
