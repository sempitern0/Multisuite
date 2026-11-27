<?php

return [
    'komga_internal_url' => env('KOMGA_INTERNAL_URL', 'http://komga-gateway:8080'),
    'video_internal_url' => env('JELLYFIN_INTERNAL_URL', 'http://jellyfin:8096'),
    'reading_autologin' => env('KAVITA_AUTOLOGIN_ENABLED', false),
    'music_autologin' => env('NAVIDROME_AUTOLOGIN_ENABLED', false),
    'music_session_bridge' => env('NAVIDROME_SESSION_BRIDGE_ENABLED', false),
    // Mis finanzas (docs/PERSONAL_FINANCES.md): personal income and expenses for every account.
    'finances' => ['enabled' => (bool) env('PORTAL_FINANCES', true)],
    'services' => [
        'komga' => [
            'name' => 'Lectura',
            'icon' => 'book',
            'description' => 'Tus libros y cómics, listos para leer.',
            'category' => 'Leer',
            'hidden_when_disabled' => true,
            'enabled' => env('PORTAL_KOMGA_ENABLED', false),
            'url' => env('PORTAL_KOMGA_URL', ''),
        ],
        'music' => [
            'name' => 'Música',
            'icon' => 'music',
            'description' => 'Tus álbumes, artistas y listas en Navidrome.',
            'category' => 'Escuchar',
            'enabled' => env('PORTAL_MUSIC_ENABLED', false),
            'url' => env('PORTAL_MUSIC_URL', ''),
        ],
        'video' => [
            'name' => 'Vídeo',
            'icon' => 'video',
            'description' => 'Tus películas, series y vídeos en Jellyfin.',
            'category' => 'Ver',
            'hidden_when_disabled' => true,
            'enabled' => env('PORTAL_VIDEO_ENABLED', false),
            'url' => env('PORTAL_VIDEO_URL', ''),
        ],
        'reading' => [
            'name' => 'Lectura',
            'hidden_when_disabled' => true,
            'icon' => 'book',
            'description' => 'Novelas, cómics, manga y documentación técnica en Kavita.',
            'category' => 'Leer',
            'enabled' => env('PORTAL_READING_ENABLED', false),
            'url' => env('PORTAL_READING_URL', ''),
        ],
    ],
];
