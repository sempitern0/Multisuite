<?php

namespace App\Services\Storage;

class VolumeProbe
{
    /** @return array{status: string, device: ?string, total: ?int, free: ?int} */
    public function inspect(string $path, string $id): array
    {
        $unknown = ['status' => 'unavailable', 'device' => null, 'total' => null, 'free' => null];
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            return $unknown;
        }
        clearstatcache();
        $root = realpath($path);
        if ($root === false || ! is_dir($root) || ! is_readable($root)) {
            return $unknown;
        }
        $marker = $root.'/.multisuite-volume-id';
        if (is_link($marker) || ! is_file($marker) || ! is_readable($marker)) {
            return $unknown;
        }
        $identity = @file_get_contents($marker, false, null, 0, 128);
        if ($identity === false || trim($identity) !== $id) {
            return $unknown;
        }
        $stat = @stat($root);
        $total = @disk_total_space($root);
        $free = @disk_free_space($root);
        if ($stat === false || $total === false || $free === false || $total <= 0 || $free < 0 || $free > $total || PHP_OS_FAMILY !== 'Linux') {
            return $unknown;
        }

        return [
            'status' => 'observed',
            'device' => (string) $stat['dev'],
            'total' => (int) $total,
            'free' => (int) $free,
        ];
    }
}
