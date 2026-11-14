<?php

return [
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
    ],
];
