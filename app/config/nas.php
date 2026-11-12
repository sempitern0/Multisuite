<?php

return [
    // JSON is decoded only by the inventory, never while booting the application.
    'uploads_enabled' => env('NAS_UPLOADS_ENABLED', false),
    'upload_location' => env('NAS_UPLOAD_LOCATION', ''),
    'upload_max_bytes' => env('NAS_UPLOAD_MAX_BYTES', 104857600),
    'volumes' => env('NAS_STORAGE_VOLUMES', '[]'),
];
