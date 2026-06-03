<?php

return [
    /*
    |--------------------------------------------------------------------------
    | POS Pricing Engine v2 (server-issued signed quote)
    |--------------------------------------------------------------------------
    |
    | When enabled, the POS frontend (web + mobile) requests a signed quote
    | from the server and submits the quote_id back on sale, instead of
    | computing totals client-side. Default off — see Phase 2 rollout plan.
    |
    */
    'pos_quote_v2' => env('FEATURE_POS_QUOTE_V2', false),

    /*
    |--------------------------------------------------------------------------
    | Multi-mode making charges (percentage / per-gram / fixed)
    |--------------------------------------------------------------------------
    |
    | Master switch for the making-charge mode rollout. When OFF (default),
    | making is fixed-only and pricing / canonical-quote output is
    | byte-identical to pre-rollout behaviour. MC-2 gates the canonical-JSON
    | extension on this flag; MC-4 gates the UI mode selectors; validators only
    | accept a non-fixed making_charge_type when this is ON.
    |
    */
    'making_charge_modes' => env('FEATURE_MAKING_CHARGE_MODES', false),
];
