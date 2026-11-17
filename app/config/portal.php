<?php

return [
    'reading_autologin' => env('KAVITA_AUTOLOGIN_ENABLED', false),
    'music_autologin' => env('NAVIDROME_AUTOLOGIN_ENABLED', false),
    'music_session_bridge' => env('NAVIDROME_SESSION_BRIDGE_ENABLED', false),
    'services' => [
        'music' => [
            'name' => 'Música',
            'icon' => 'music',
            'description' => 'Tus álbumes, artistas y listas en Navidrome.',
            'category' => 'Escuchar',
            'enabled' => env('PORTAL_MUSIC_ENABLED', false),
            'url' => env('PORTAL_MUSIC_URL', ''),
        ],
        'reading' => [
            'name' => 'Lectura',
            'icon' => 'book',
            'description' => 'Novelas, cómics, manga y documentación técnica en Kavita.',
            'category' => 'Leer',
            'enabled' => env('PORTAL_READING_ENABLED', false),
            'url' => env('PORTAL_READING_URL', ''),
        ],
    ],
];
