<?php

namespace App\Enums;

enum ServiceAuditAction: string
{
    case AccessGranted = 'service.access_granted';
    case AccessRevoked = 'service.access_revoked';
    case AccountCreated = 'service.account_created';
    case AccountLinked = 'service.account_linked';
    case AccountDeleted = 'service.account_deleted';
    case SyncSucceeded = 'service.sync_succeeded';
    case SyncFailed = 'service.sync_failed';

    public function label(): string
    {
        return match ($this) {
            self::AccessGranted => 'Acceso concedido',
            self::AccessRevoked => 'Acceso revocado',
            self::AccountCreated => 'Cuenta de servicio creada',
            self::AccountLinked => 'Cuenta de servicio vinculada',
            self::AccountDeleted => 'Cuenta de servicio eliminada',
            self::SyncSucceeded => 'Sincronización completada',
            self::SyncFailed => 'Sincronización fallida',
        };
    }
}
