<?php

namespace App\Enums;

/** private_uploads.state. The values are the database contract (migration 2026_11_27_000000 adds the CHECK). */
enum UploadState: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Cancelled = 'cancelled';
    case Purged = 'purged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
