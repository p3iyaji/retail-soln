<?php

return [
    'name' => 'Authentication',

    /*
    | Roles that may enable two-factor authentication in Story 1.2.
    | Full RBAC enforcement lands in Story 1.4.
    */
    'two_factor_eligible_roles' => [
        'super_admin',
        'org_admin',
    ],

    'sanctum_token_name' => 'api',
];
