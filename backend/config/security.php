<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Rate limits (requests per minute)
    |--------------------------------------------------------------------------
    |
    | `api` applies to the whole /api group, keyed by authenticated user or IP.
    | `payment_webhooks` applies to the unauthenticated provider callbacks.
    |
    */
    'rate_limits' => [
        'api' => (int) env('API_RATE_LIMIT', 120),
        'payment_webhooks' => (int) env('PAYMENT_WEBHOOK_RATE_LIMIT', 60),
    ],

    'headers' => [
        'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
        'referrer_policy' => env('SECURITY_REFERRER_POLICY', 'strict-origin-when-cross-origin'),
        'frame_options' => env('SECURITY_FRAME_OPTIONS', 'DENY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | The v1 stack terminates TLS at nginx, so X-Forwarded-* must be honoured
    | for HTTPS detection and for throttle keys to see the real client IP.
    |
    */
    'trusted_proxies' => env('TRUSTED_PROXIES', '*'),
];
