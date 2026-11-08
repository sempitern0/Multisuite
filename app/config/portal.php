<?php

return [
    'services' => [
        'music' => [
            'name' => 'Música',
            'description' => 'Tus álbumes, artistas y listas en Navidrome.',
            'category' => 'Escuchar',
            'enabled' => env('PORTAL_MUSIC_ENABLED', false),
            'url' => env('PORTAL_MUSIC_URL', ''),
        ],
    ],
];
