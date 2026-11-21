<?php

namespace App\Support\Finance;

/** Fixed categories, stored by key so labels can change without touching history. */
final class Categories
{
    public const KINDS = ['expense' => 'Gasto', 'income' => 'Ingreso'];

    public const BY_KIND = [
        'expense' => [
            'food' => 'Alimentación',
            'housing' => 'Vivienda',
            'bills' => 'Suministros',
            'transport' => 'Transporte',
            'health' => 'Salud',
            'education' => 'Educación',
            'leisure' => 'Ocio',
            'shopping' => 'Compras',
            'other' => 'Otros gastos',
        ],
        'income' => [
            'salary' => 'Nómina',
            'freelance' => 'Trabajos por cuenta propia',
            'refund' => 'Devoluciones',
            'gift' => 'Regalos',
            'interest' => 'Intereses',
            'other' => 'Otros ingresos',
        ],
    ];

    public static function valid(string $kind, string $category): bool
    {
        return isset(self::BY_KIND[$kind][$category]);
    }

    public static function label(string $kind, string $category): string
    {
        return self::BY_KIND[$kind][$category] ?? 'Sin categoría';
    }

    /** @return array<string, string> */
    public static function options(string $kind): array
    {
        return self::BY_KIND[$kind] ?? [];
    }
}
