<?php

return [
    'name' => 'CustomerPortal',

    /*
    |--------------------------------------------------------------------------
    | Portal organization (MVP single-tenant)
    |--------------------------------------------------------------------------
    |
    | When set, portal register/login scopes CRM customers to this organization.
    | When null, the first organization (lowest id) is used.
    |
    */
    'organization_id' => env('CUSTOMER_PORTAL_ORGANIZATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Default store for catalogue price / stock signal (MVP)
    |--------------------------------------------------------------------------
    |
    | When set, portal catalogue resolves price and stock against this store
    | unless the request passes a valid storeId. When null, the first active
    | store in the portal organization is used.
    |
    */
    'default_store_id' => env('CUSTOMER_PORTAL_DEFAULT_STORE_ID'),

    'sanctum_token_name' => 'portal',
    'sanctum_token_ability' => 'portal',

    'verification_expires_minutes' => (int) env('CUSTOMER_PORTAL_VERIFY_EXPIRES', 60),

    /*
    |--------------------------------------------------------------------------
    | Flat delivery fee for home_delivery checkout (MVP)
    |--------------------------------------------------------------------------
    */
    'delivery_fee' => env('CUSTOMER_PORTAL_DELIVERY_FEE', '0.0000'),

    /*
    |--------------------------------------------------------------------------
    | Unpaid reservation TTL (minutes)
    |--------------------------------------------------------------------------
    |
    | Awaiting-payment portal orders older than this are cancelled by
    | `portal:expire-unpaid-reservations`, releasing reserved stock back to
    | available. Paid orders are never expired by this job.
    |
    */
    'reservation_ttl_minutes' => (int) env('CUSTOMER_PORTAL_RESERVATION_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Customer-facing support details (contact page + chat helper)
    |--------------------------------------------------------------------------
    */
    'support_email' => env('CUSTOMER_PORTAL_SUPPORT_EMAIL'),
    'support_phone' => env('CUSTOMER_PORTAL_SUPPORT_PHONE'),
    'business_hours' => env('CUSTOMER_PORTAL_BUSINESS_HOURS', 'Mon–Sat 9:00–18:00'),
];
