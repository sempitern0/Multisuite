<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Human-friendly byte sizes for administrators. Binary units (1 GB = 1024³ bytes),
 * the same convention Windows uses, so values match what operators see on their PCs.
 */
final class Bytes
{
    /** @var array<string, int> */
    public const UNITS = [
        'MB' => 1_048_576,
        'GB' => 1_073_741_824,
        'TB' => 1_099_511_627_776,
    ];

    /** @return array<int, string> */
    public static function presets(): array
    {
        $presets = [0 => 'Sin espacio (bloqueado)'];
        foreach ([1, 5, 10, 25, 50, 100, 250, 500] as $gigabytes) {
            $presets[$gigabytes * self::UNITS['GB']] = $gigabytes.' GB';
        }
        foreach ([1, 2, 4] as $terabytes) {
            $presets[$terabytes * self::UNITS['TB']] = $terabytes.' TB';
        }

        return $presets;
    }

    /** Converts "1,5" + "GB" into bytes; rejects scientific notation, negatives and over-long input. */
    public static function fromInput(string $amount, string $unit): int
    {
        $amount = str_replace(',', '.', trim($amount));
        if (! isset(self::UNITS[$unit]) || ! preg_match('/^(\d{1,6})(?:\.(\d{1,2}))?$/D', $amount, $matches)) {
            throw new InvalidArgumentException('Escribe una cantidad como 50 o 1,5 y elige MB, GB o TB.');
        }
        $multiplier = self::UNITS[$unit];
        $fraction = isset($matches[2]) ? (int) str_pad($matches[2], 2, '0') : 0;

        return (int) $matches[1] * $multiplier + intdiv($fraction * $multiplier, 100);
    }

    public static function format(?int $bytes): string
    {
        if ($bytes === null) {
            return 'Desconocido';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $value = (float) max(0, $bytes);
        $index = 0;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        $decimals = $index === 0 || $value >= 100 || floor($value) === $value ? 0 : 1;

        return number_format($value, $decimals, ',', '.').' '.$units[$index];
    }

    public static function percent(int $part, int $total): float
    {
        return $total <= 0 ? 0.0 : round(min(100, max(0, $part / $total * 100)), 1);
    }
}
