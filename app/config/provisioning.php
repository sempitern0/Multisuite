<?php

return [
    'komga' => ['enabled' => env('KOMGA_PROVISIONING_ENABLED', false)],
    'music' => [
        'enabled' => env('NAVIDROME_PROVISIONING_ENABLED', false),
        'url' => env('NAVIDROME_INTERNAL_URL', ''),
        'username' => env('NAVIDROME_ADMIN_USERNAME', ''),
        'password' => env('NAVIDROME_ADMIN_PASSWORD', ''),
    ],
    'reading' => [
        'enabled' => env('KAVITA_PROVISIONING_ENABLED', false),
        'url' => env('KAVITA_INTERNAL_URL', ''),
        'username' => env('KAVITA_ADMIN_USERNAME', ''),
        'password' => env('KAVITA_ADMIN_PASSWORD', ''),
        // Created by portal:reading-setup when missing; folders are paths inside the Kavita container
        // (compose.reading.yaml). Type: 0 Manga, 1 Comic, 2 Book. Metadata provider: the one Kavita
        // accepts for that type (external matching stays off; it needs Kavita+).
        'libraries' => [
            ['name' => 'Libros', 'type' => 2, 'folder' => '/books', 'provider' => 2],
            ['name' => 'Cómics', 'type' => 1, 'folder' => '/comics', 'provider' => 4],
            ['name' => 'Manga', 'type' => 0, 'folder' => '/manga', 'provider' => 3],
            ['name' => 'Documentación técnica', 'type' => 2, 'folder' => '/docs', 'provider' => 2],
        ],
    ],
];
