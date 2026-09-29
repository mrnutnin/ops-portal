<?php

return [
    'entitlement_allowed_hosts' => array_filter(array_map('trim', explode(',', (string) env('OPS_ENTITLEMENT_ALLOWED_HOSTS', '')))),
    'admin' => [
        'name' => env('OPS_ADMIN_NAME'),
        'email' => env('OPS_ADMIN_EMAIL'),
        'password' => env('OPS_ADMIN_PASSWORD'),
    ],
];
