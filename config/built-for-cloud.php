<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Bobbin',
        'slug' => 'bobbin',
        'description' => 'Catch third-party webhooks, inspect exactly what arrived, and replay them onward.',
        'icon' => 'https://scalpels.app/img/products/transparent/bobbin.svg',
        'product_url' => 'https://scalpels.app',
    ],
    'credentials' => [
        'guard' => 'bfc',
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
        ],
    ],
    'ui' => [
        'landing_page' => false,
        'member_management' => false,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => false,
        'managed_transitions' => false,
        'credential_purposes' => [
        ],
    ],
];
