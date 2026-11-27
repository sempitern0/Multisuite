<?php

namespace App\Enums;

/** file_exports.state. The values are the database contract (migration 2026_11_27_000000 adds the CHECK). */
enum ExportState: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Ready = 'ready';
    case Failed = 'failed';
    case Cleaned = 'cleaned';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
