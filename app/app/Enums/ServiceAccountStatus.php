<?php

namespace App\Enums;

/** service_accounts.status. The values are the database contract (migration 2026_11_27_000000 adds the CHECK). */
enum ServiceAccountStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case AwaitingPassword = 'awaiting_password';
    case LockPending = 'lock_pending';
    case Locked = 'locked';
    case Expired = 'expired';
    case Conflict = 'conflict';
    case Configuration = 'configuration';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
