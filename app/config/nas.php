<?php

return [
    // JSON is decoded only by the inventory, never while booting the application.
    'uploads_enabled' => env('NAS_UPLOADS_ENABLED', false),

    // Encryption at rest of private files and ZIP exports (App\Services\Storage\FileCipher).
    // The key lives only in this secret configuration: back it up outside the server.
    'encrypt_files' => (bool) env('NAS_ENCRYPT_FILES', true),
    'files_key' => env('NAS_FILES_KEY', ''),
    'upload_location' => env('NAS_UPLOAD_LOCATION', ''),
    // Safety ceiling per file (100 GiB). Files arrive in 8 MiB chunks, so web server and
    // PHP body limits do not apply; the real bound is each person's space.
    'upload_max_bytes' => (int) env('NAS_UPLOAD_MAX_BYTES', 107374182400),
    'volumes' => env('NAS_STORAGE_VOLUMES', '[]'),
    // lsblk inventory written on the server by `make nas-discover` (read-only display).
    'hardware_snapshot' => env('NAS_HARDWARE_SNAPSHOT', ''),
    // Result of the last `make nas-backup` (read-only display); older than this is flagged.
    'backup_status' => env('NAS_BACKUP_STATUS', ''),
    'backup_max_age_hours' => (int) env('NAS_BACKUP_MAX_AGE_HOURS', 48),

    // Detected (never client-declared) types accepted for private files. Archives,
    // executables, HTML and SVG are deliberately absent: they are never extracted,
    // run or rendered by the portal.
    'allowed_mimes' => [
        'audio/mpeg', 'audio/flac', 'audio/x-flac', 'audio/ogg', 'application/ogg', 'audio/wav', 'audio/x-wav', 'audio/mp4',
        'application/pdf', 'text/plain', 'text/markdown', 'text/csv', 'application/json',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        // Played by the browser as stored; the portal never transcodes.
        'video/mp4', 'video/webm', 'video/quicktime',
        'application/epub+zip',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.oasis.opendocument.presentation',
    ],

    // Pictures (JPEG, PNG, GIF, WebP) lose their private metadata on upload with ExifTool: GPS,
    // camera, dates, XMP, IPTC, thumbnails. Only orientation and colour profile stay. If it cannot
    // be removed the upload is refused. Disable only where ExifTool is not available.
    'strip_image_metadata' => (bool) env('NAS_STRIP_IMAGE_METADATA', true),

    // Lossless only: JPEG is repacked by jpegtran (same pixels, remaining metadata kept). Nothing is re-encoded.
    'optimize_images' => (bool) env('NAS_OPTIMIZE_IMAGES', true),
    // Larger JPEGs are stored as received: optimising needs the whole file at the end of the upload.
    'optimize_max_bytes' => (int) env('NAS_OPTIMIZE_MAX_BYTES', 67108864),

    // Development only (portal:dev-bootstrap): default per-person space applied when
    // none has been chosen yet. Production leaves this at 0.
    'bootstrap_default_quota_bytes' => (int) env('NAS_BOOTSTRAP_DEFAULT_QUOTA_BYTES', 0),

    // Mis archivos retention, applied daily by portal:files-purge.
    'trash_days' => (int) env('NAS_TRASH_DAYS', 30),
    'max_versions' => (int) env('NAS_MAX_VERSIONS', 5),

    // ZIP downloads: built during the request up to this size, otherwise by the
    // scheduler (portal:exports-run); offered for this many hours, then deleted.
    'export_sync_max_bytes' => (int) env('NAS_EXPORT_SYNC_MAX_BYTES', 52428800),
    'export_ttl_hours' => (int) env('NAS_EXPORT_TTL_HOURS', 24),

    // Read-only previews (Mis archivos). Larger text is offered as download only.
    'preview_text_max_bytes' => 1048576,
];
