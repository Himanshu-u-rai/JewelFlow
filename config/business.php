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
    | Platform Billing Invoice Settings
    |--------------------------------------------------------------------------
    */
    'platform_invoice_prefix'   => env('PLATFORM_INVOICE_PREFIX', 'JFINV-'),
    'platform_invoice_gst_rate' => (float) env('PLATFORM_INVOICE_GST_RATE', 18.0),
];
