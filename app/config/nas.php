<?php

return [
    // JSON is decoded only by the inventory, never while booting the application.
    'uploads_enabled' => env('NAS_UPLOADS_ENABLED', false),
    'upload_location' => env('NAS_UPLOAD_LOCATION', ''),
    // Keep at or below Nginx client_max_body_size (docker/nginx/site.conf).
    'upload_max_bytes' => env('NAS_UPLOAD_MAX_BYTES', 104857600),
    'volumes' => env('NAS_STORAGE_VOLUMES', '[]'),

    // Detected (never client-declared) types accepted for private files. Archives,
    // executables, HTML and SVG are deliberately absent: they are never extracted,
    // run or rendered by the portal.
    'allowed_mimes' => [
        'audio/mpeg', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'application/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
        'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/epub+zip',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
    ],

    // Read-only previews (Mis archivos). Larger text is offered as download only.
    'preview_text_max_bytes' => 1048576,
];
