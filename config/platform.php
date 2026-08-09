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
    | Ops Alert Email
    |--------------------------------------------------------------------------
    |
    | The JewelFlows internal operations inbox that receives platform alerts
    | (new shop created, payment applied / unresolved / permanently failed /
    | reconciled). Never a hardcoded personal address — set PLATFORM_ALERT_EMAIL
    | in the environment. Empty = alerts are logged and suppressed (never sent).
    |
    | NOTE: PLATFORM_ALERT_EMAIL is ALSO read by unrelated scheduled pipelines
    | (platform:detect-fraud, platform:check-shop-health, platform:evaluate-alerts).
    | To enable ONLY the subscription/shop pipeline without turning those on, set
    | SUBSCRIPTION_ALERT_EMAIL instead — SendOpsAlertEmail prefers it and only
    | falls back to alert_email when it is empty.
    |
    */
    'alert_email' => env('PLATFORM_ALERT_EMAIL', ''),

    // Isolated recipient for the subscription/shop ops-alert pipeline only.
    // Empty → fall back to alert_email (which also feeds fraud/health/evaluate).
    'subscription_alert_email' => env('SUBSCRIPTION_ALERT_EMAIL', ''),

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
        // No hardcoded default: an explicit env override wins, otherwise the URL is
        // derived from the current request host (App\Support\Realm::dhiranRegisterUrl)
        // so staging never links to production. See config note above.
        'dhiran_register_url' => env('DHIRAN_REGISTER_URL'),
        'erp_register_url'    => env('ERP_REGISTER_URL', 'https://jewelflows.com/register'),
    ],
];
