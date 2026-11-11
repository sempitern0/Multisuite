<?php

return [
    // JSON is decoded only by the inventory, never while booting the application.
    'volumes' => env('NAS_STORAGE_VOLUMES', '[]'),
];
