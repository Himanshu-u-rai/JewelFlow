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
];
