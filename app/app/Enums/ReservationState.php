<?php

namespace App\Enums;

/** storage_reservations.state. The values are the database contract (migration 2026_11_27_000000 adds the CHECK). */
enum ReservationState: string
{
    case Reserved = 'reserved';
    case Committed = 'committed';
    case Released = 'released';
    case Purged = 'purged';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
