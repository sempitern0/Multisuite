<?php

namespace App\Enums;

/** Account role. Stored as text in users.role (CHECK in PostgreSQL); compare with ->value. The values are the database contract (migration 2026_11_27_000000 adds the CHECK). */
enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';

    /** Human name shown in the interface. */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Propietario',
            self::Admin => 'Administrador',
            self::Member => 'Miembro',
        };
    }

    /** How much the role can do (role badge): owner 3, admin 2, member 1. */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Member => 1,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
