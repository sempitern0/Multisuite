<?php

return [
    'music' => [
        'enabled' => env('NAVIDROME_PROVISIONING_ENABLED', false),
        'url' => env('NAVIDROME_INTERNAL_URL', ''),
        'username' => env('NAVIDROME_ADMIN_USERNAME', ''),
        'password' => env('NAVIDROME_ADMIN_PASSWORD', ''),
    ],
];
