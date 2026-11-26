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
    | The subscription/shop/payment ops pipeline does NOT use this key at all —
    | it sends only to SUBSCRIPTION_ALERT_EMAIL below (fail-closed, no fallback).
    |
    */
    'alert_email' => env('PLATFORM_ALERT_EMAIL', ''),

    // Dedicated, FAIL-CLOSED recipient for the subscription/shop/payment ops-alert
    // pipeline (SendOpsAlertEmail) ONLY. Deliberately NO fallback to alert_email:
    // a fallback would let PLATFORM_ALERT_EMAIL silently enable this pipeline (and
    // vice-versa entangle it with fraud/health/evaluate). Blank → sends are
    // suppressed and logged. A single address — comma-separated lists not accepted.
    'subscription_alert_email' => env('SUBSCRIPTION_ALERT_EMAIL', ''),
];
