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

    /*
    |--------------------------------------------------------------------------
    | Cross-Promotion (Phase 4)
    |--------------------------------------------------------------------------
    |
    | Lightweight, non-intrusive product cross-promotion. An ERP customer who
    | does not yet use Dhiran sees a calm "Explore Dhiran" card; a Dhiran customer
    | who does not yet use the Retail ERP sees an "Explore JewelFlow ERP" card.
    |
    | Each card links to the OTHER product's separate register front door. It
    | never auto-creates an account or grants an edition — the customer-facing
    | account separation stays intact (they register separately in each product).
    |
    | URLs are config-driven so they can be pointed at staging/other domains
    | without code changes.
    |
    */
    'cross_promotion' => [
        'enabled'             => env('CROSS_PROMOTION_ENABLED', true),
        'dhiran_register_url' => env('DHIRAN_REGISTER_URL', 'https://dhiran.jewelflows.com/register'),
        'erp_register_url'    => env('ERP_REGISTER_URL', 'https://jewelflows.com/register'),
    ],
];
