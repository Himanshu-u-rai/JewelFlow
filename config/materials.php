<?php

/**
 * JewelFlow Material Configuration.
 *
 * Phase 0 — this file establishes the tier scaffold that Phase 1's
 * MetalRegistry will absorb as its authoritative source. During Phase 0,
 * only Tier 1 metals are operationally supported; Tier 2 is reserved
 * for Phase 1 activation behind a feature flag.
 *
 * CONSTITUTIONAL: any code that references a metal literal must consult
 * this config (or in Phase 1, MetalRegistry) — see CONSTITUTION.md
 * Article XV when it lands.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Metal Tiers
    |--------------------------------------------------------------------------
    |
    | Tier 1 (Full Support): every operational flow without restriction.
    | Tier 2 (Limited Support): inventory, invoicing, manual valuation,
    |    reconciliation, reporting — but NOT dhiran collateral, exchange
    |    payment, live-rate auto-fetch, or auto-reprice.
    | Tier 3 (Blocked): rejected at controller, service, and DB layers.
    |
    */

    'tier_1' => ['gold', 'silver'],

    // Phase 1: platinum and copper join as Tier 2 limited support.
    // Operationally:
    //   - inventory: ALLOWED
    //   - invoicing: ALLOWED
    //   - manual valuation: REQUIRED (no auto-reprice for Tier 2)
    //   - reconciliation: ALLOWED (separated by metal_type)
    //   - reporting: VISIBLE
    //   - dhiran collateral: BLOCKED
    //   - exchange payment (old-metal trade-in): BLOCKED
    //   - live-rate auto-fetch: BLOCKED
    //   - retailer auto-reprice job: SKIPPED
    'tier_2' => ['platinum', 'copper'],

    // Tier 3 is the explicit-reject list. Enforced at three layers:
    //   - MetalRegistry::assertSupported() throws LogicException
    //   - Controller validators reject (422)
    //   - DB CHECK constraints reject any metal not in tier_1 ∪ tier_2
    'tier_3_blocked_examples' => [
        'palladium',
        'brass',
        'rhodium',
        // Any metal not in tier_1 or tier_2 is effectively Tier 3 blocked.
    ],

    /*
    |--------------------------------------------------------------------------
    | Live Metal Rate Auto-Fetch
    |--------------------------------------------------------------------------
    |
    | The FetchLiveMetalRatesJob exists but is dormant by default. Setting
    | this to true authorizes the job to write to metal_rates. The job is
    | never scheduled automatically — it only runs if explicitly dispatched.
    |
    | Constitutional: per CONSTITUTION.md §6 Warning 1 (when Article XV lands),
    | external rate fetching requires explicit operator approval per shop
    | before any fetched rate propagates to invoices.
    |
    */

    'fetch_enabled' => env('MATERIALS_FETCH_ENABLED', false),

];
