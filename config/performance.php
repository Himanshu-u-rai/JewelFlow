<?php

return [
    'rate_limits' => [
        // POS API reads: /api/items, /api/customers
        'pos_read_per_minute' => env('API_POS_READ_RATE_LIMIT', 120),
        // POS API writes: /api/sale
        'pos_sale_per_minute' => env('API_POS_SALE_RATE_LIMIT', 30),
    ],

    'cache' => [
        // Search result cache for POS read endpoints
        'pos_search_ttl_seconds' => env('POS_SEARCH_CACHE_TTL_SECONDS', 120),
        // Dashboard aggregate cache
        'dashboard_ttl_seconds' => env('DASHBOARD_CACHE_TTL_SECONDS', 300),
        // Default search terms to pre-warm in scheduled cache warm-up
        'warmup_search_terms' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CACHE_WARMUP_SEARCH_TERMS', 'ring,chain,necklace,bracelet,pendant'))
        ))),
    ],
];

