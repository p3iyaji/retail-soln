<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Structured logging
    |--------------------------------------------------------------------------
    |
    | When true, the default log stack includes a JSON channel suitable for
    | log aggregators (Docker stdout, CloudWatch, etc.).
    |
    */
    'logging' => [
        'json' => (bool) env('OBSERVABILITY_LOG_JSON', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Slow query logging
    |--------------------------------------------------------------------------
    */
    'slow_query' => [
        'enabled' => (bool) env('OBSERVABILITY_SLOW_QUERY_LOG', true),
        'threshold_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus metrics
    |--------------------------------------------------------------------------
    */
    'metrics' => [
        'enabled' => (bool) env('OBSERVABILITY_METRICS_ENABLED', false),
        'token' => env('OBSERVABILITY_METRICS_TOKEN'),
    ],

];
