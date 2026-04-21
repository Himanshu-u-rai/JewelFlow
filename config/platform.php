<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Subscription Enforcement
    |--------------------------------------------------------------------------
    |
    | Keep billing/subscription architecture in place but allow enforcement
    | to be toggled without removing middleware, tables, or services.
    |
    */
    'enforce_subscriptions' => env('PLATFORM_ENFORCE_SUBSCRIPTIONS', false),
];
