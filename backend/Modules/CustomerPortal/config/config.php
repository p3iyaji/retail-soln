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
];
