<?php

namespace App\Services\Storage;

/**
 * Read-only view of the server's disks written by `make nas-discover` (lsblk JSON)
 * and mounted into the container. The portal never inspects host devices itself;
 * a missing or malformed snapshot simply yields nothing to show.
 */
class HardwareSnapshot
{
    private const MAX_BYTES = 2_000_000;

    /**
     * @return array{host: string, generated_at: string, disks: list<array{name: string, model: string, size: ?int, removable: bool, partitions: list<array{name: string, fstype: string, size: ?int, mountpoint: string, fs_size: ?int, fs_available: ?int, in_use: bool}>}>}|null
     */
    public function read(): ?array
    {
        $path = (string) config('nas.hardware_snapshot', '');
        if ($path === '' || ! is_file($path) || is_link($path) || filesize($path) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true, 64);
        if (! is_array($data) || ! is_array($data['lsblk']['blockdevices'] ?? null)) {
            return null;
        }
        $targets = array_values(array_filter((array) ($data['portal_targets'] ?? []), 'is_string'));
        $disks = [];
        foreach ($data['lsblk']['blockdevices'] as $device) {
            if (! is_array($device) || ($device['type'] ?? null) !== 'disk' || str_starts_with((string) ($device['name'] ?? ''), 'loop')) {
                continue;
            }
            // A filesystem written directly on the disk (no partition table) is shown as its own entry.
            $children = is_array($device['children'] ?? null) ? $device['children'] : [];
            if ($children === [] && ! empty($device['fstype'])) {
                $children = [$device];
            }
            $partitions = [];
            foreach ($children as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $mountpoint = (string) ($child['mountpoint'] ?? '');
                $partitions[] = [
                    'name' => (string) ($child['name'] ?? ''),
                    'fstype' => (string) ($child['fstype'] ?? ''),
                    'size' => $this->bytes($child['size'] ?? null),
                    'mountpoint' => $mountpoint,
                    'fs_size' => $this->bytes($child['fssize'] ?? null),
                    'fs_available' => $this->bytes($child['fsavail'] ?? null),
                    'in_use' => $mountpoint !== '' && in_array($mountpoint, $targets, true),
                ];
            }
            $disks[] = [
                'name' => (string) ($device['name'] ?? ''),
                'model' => trim((string) ($device['model'] ?? '')),
                'size' => $this->bytes($device['size'] ?? null),
                'removable' => in_array($device['rm'] ?? false, [true, 1, '1'], true),
                'partitions' => $partitions,
            ];
        }

        return [
            'host' => (string) ($data['host'] ?? ''),
            'generated_at' => (string) ($data['generated_at'] ?? ''),
            'disks' => $disks,
        ];
    }

    /** lsblk -b emits numbers (or numeric strings in older util-linux). */
    private function bytes(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^\d{1,19}$/D', $value) ? (int) $value : null;
    }
}
