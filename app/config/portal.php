<?php

return [
    'komga_internal_url' => env('KOMGA_INTERNAL_URL', 'http://komga-gateway:8080'),
    'video_internal_url' => env('JELLYFIN_INTERNAL_URL', 'http://jellyfin:8096'),
    'reading_autologin' => env('KAVITA_AUTOLOGIN_ENABLED', false),
    'music_autologin' => env('NAVIDROME_AUTOLOGIN_ENABLED', false),
    'music_session_bridge' => env('NAVIDROME_SESSION_BRIDGE_ENABLED', false),
    // Mis finanzas (docs/PERSONAL_FINANCES.md): personal income and expenses for every account.
    'finances' => ['enabled' => (bool) env('PORTAL_FINANCES', true)],
    // Folder MeTube writes to, shared read-write with the portal (deploy/compose.portal-downloads.example.yaml).
    'downloads_staging_path' => env('DOWNLOADS_STAGING_PATH', ''),

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
        // Descargas (MeTube, docs/DOWNLOADS.md): only owner and administrators.
        'downloads' => [
            'name' => 'Descargas',
            'icon' => 'download',
            'description' => 'Descarga vídeos y audio de webs con MeTube y guárdalos en Mis archivos.',
            'category' => 'Herramientas',
            'admin_only' => true,
            'hidden_when_disabled' => true,
            'enabled' => env('PORTAL_DOWNLOADS_ENABLED', false),
            'url' => env('PORTAL_DOWNLOADS_URL', ''),
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
