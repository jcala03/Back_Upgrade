<?php

use App\Models\User;

return [
    'demo_users' => [
        'enabled' => env('SEED_DEMO_USERS', false),
        'accounts' => [
            [
                'name' => env('DEMO_ADMIN_NAME', 'Local administrator'),
                'email' => env('DEMO_ADMIN_EMAIL'),
                'password' => env('DEMO_ADMIN_PASSWORD'),
                'role' => User::ROLE_ADMIN,
            ],
            [
                'name' => env('DEMO_OPERATOR_NAME', 'Local operator'),
                'email' => env('DEMO_OPERATOR_EMAIL'),
                'password' => env('DEMO_OPERATOR_PASSWORD'),
                'role' => User::ROLE_USER,
            ],
        ],
    ],
];
