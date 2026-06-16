<?php

return [
    /*
    |--------------------------------------------------------------------------
    | GST Configuration
    |--------------------------------------------------------------------------
    */
    'gst_rate_default' => (float) env('BUSINESS_GST_RATE', 3.0),

    /*
    |--------------------------------------------------------------------------
    | Gold Rate Validation Limits (INR per gram)
    |--------------------------------------------------------------------------
    */
    'gold_rate_min' => (int) env('BUSINESS_GOLD_RATE_MIN', 1000),
    'gold_rate_max' => (int) env('BUSINESS_GOLD_RATE_MAX', 150000),

    /*
    |--------------------------------------------------------------------------
    | Subscription Grace Period
    |--------------------------------------------------------------------------
    */
    'subscription_grace_days' => (int) env('BUSINESS_GRACE_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Free Trial
    |--------------------------------------------------------------------------
    | Length of a self-serve free trial, in days. A trial needs no payment and
    | no card; the shop is fully writable during it and drops to read-only when
    | it ends (data preserved). Default is one month so a shop has time to set
    | up before deciding to pay.
    */
    'subscription_trial_days' => (int) env('BUSINESS_TRIAL_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Platform Billing Invoice Settings
    |--------------------------------------------------------------------------
    */
    'platform_invoice_prefix'   => env('PLATFORM_INVOICE_PREFIX', 'JFINV-'),
    'platform_invoice_gst_rate' => (float) env('PLATFORM_INVOICE_GST_RATE', 18.0),
];
