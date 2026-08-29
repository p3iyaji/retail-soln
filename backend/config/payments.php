<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default payment provider for portal checkout
    |--------------------------------------------------------------------------
    */
    'default' => env('PAYMENT_PROVIDER', 'manual'),

    /*
    |--------------------------------------------------------------------------
    | Portal SPA base URL (success/cancel defaults)
    |--------------------------------------------------------------------------
    */
    'portal_frontend_url' => env('PORTAL_FRONTEND_URL', env('APP_URL', 'http://localhost:5175')),

    /*
    |--------------------------------------------------------------------------
    | Allowed redirect origins (comma-separated absolute origins)
    |--------------------------------------------------------------------------
    |
    | Client successUrl / cancelUrl must match one of these origins.
    | APP_URL and portal_frontend_url are always included.
    |
    */
    'allowed_redirect_origins' => env(
        'PAYMENT_ALLOWED_REDIRECT_ORIGINS',
        'http://localhost:5175,http://127.0.0.1:5175,http://localhost:8000,http://127.0.0.1:8000'
    ),

    'manual' => [
        'enabled' => env('PAYMENT_MANUAL_ENABLED', true),
        // Required for POST /api/v1/webhooks/payments/manual (provider-style events).
        // Shoppers should use authenticated POST /portal/orders/{id}/confirm-payment.
        'webhook_secret' => env('PAYMENT_MANUAL_WEBHOOK_SECRET'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        // Max age (seconds) of the signed `t=` timestamp before a webhook is treated as a replay.
        'webhook_tolerance' => (int) env('STRIPE_WEBHOOK_TOLERANCE', 300),
        'currency' => env('STRIPE_CURRENCY', 'ngn'),
        'success_url' => env('STRIPE_SUCCESS_URL'),
        'cancel_url' => env('STRIPE_CANCEL_URL'),
    ],

    'paystack' => [
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'webhook_secret' => env('PAYSTACK_WEBHOOK_SECRET'),
        'currency' => env('PAYSTACK_CURRENCY', 'NGN'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
    ],
];
