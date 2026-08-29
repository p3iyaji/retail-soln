<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Documented latency targets (informational)
    |--------------------------------------------------------------------------
    |
    | Used by ops runbooks and future observability stories. Not enforced in CI.
    |
    */
    'targets' => [
        'product_search_p95_ms' => (int) env('SEARCH_P95_MS', 200),
        'sale_completion_ms' => (int) env('SALE_COMPLETION_MS', 3000),
    ],

    'search' => [
        'fuzzy_min_length' => (int) env('SEARCH_FUZZY_MIN_LENGTH', 2),
        'default_limit' => (int) env('SEARCH_DEFAULT_LIMIT', 25),
        'max_limit' => (int) env('SEARCH_MAX_LIMIT', 50),
    ],
];
