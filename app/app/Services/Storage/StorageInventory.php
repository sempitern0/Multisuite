<?php

namespace App\Services\Storage;

use InvalidArgumentException;
use JsonException;

class StorageInventory
{
    public function __construct(private VolumeProbe $probe) {}

    /** @return array<string, mixed> */
    public function inspect(): array
    {
        try {
            $volumes = json_decode((string) config('nas.volumes', '[]'), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('NAS_STORAGE_VOLUMES debe ser un array JSON válido.');
        }
        if (! is_array($volumes) || ! array_is_list($volumes) || count($volumes) > 64) {
            throw new InvalidArgumentException('Configura una lista de hasta 64 ubicaciones.');
        }
        $ids = [];
        $rows = [];
        $groups = [];
        $deviceGroups = [];
        foreach ($volumes as $volume) {
            if (! is_array($volume) || array_diff(array_keys($volume), ['id', 'group', 'path', 'reserve_bytes']) !== []) {
                throw new InvalidArgumentException('Campos de ubicación no válidos.');
            }
            foreach (['id', 'group'] as $key) {
                if (! isset($volume[$key]) || ! is_string($volume[$key]) || ! preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $volume[$key])) {
                    throw new InvalidArgumentException('Los identificadores deben ser nombres estables en minúsculas.');
                }
            }
            if (isset($ids[$volume['id']]) || ! isset($volume['path']) || ! is_string($volume['path'])
                || ! str_starts_with($volume['path'], '/') || str_contains($volume['path'], "\0")
                || ! isset($volume['reserve_bytes']) || ! is_int($volume['reserve_bytes']) || $volume['reserve_bytes'] < 0) {
                throw new InvalidArgumentException('Revisa IDs duplicados, rutas absolutas y reservas enteras no negativas.');
            }
            $ids[$volume['id']] = true;
            $observation = $this->probe->inspect($volume['path'], $volume['id']);
            $group = $volume['group'];
            $rows[] = ['id' => $volume['id'], 'group' => $group, 'status' => $observation['status']];
            $groups[$group][] = [...$observation, 'reserve' => $volume['reserve_bytes']];
            if ($observation['device'] !== null) {
                $deviceGroups[$observation['device']][$group] = true;
            }
        }
        // A shared filesystem declared as separate capacity pools must fail closed.
        $conflicts = [];
        foreach ($deviceGroups as $aliases) {
            if (count($aliases) > 1) {
                $conflicts = array_merge($conflicts, array_keys($aliases));
            }
        }
        $pools = [];
        foreach ($groups as $id => $observations) {
            $available = ! in_array($id, $conflicts, true)
                && count(array_filter($observations, fn ($row) => $row['status'] !== 'observed')) === 0;
            $reserve = max(array_column($observations, 'reserve'));
            // Multiple roots on the same pool share bytes: never sum their capacities.
            $total = $available ? min(array_column($observations, 'total')) : null;
            $free = $available ? min(array_column($observations, 'free')) : null;
            $pools[] = [
                'id' => $id,
                'status' => in_array($id, $conflicts, true) ? 'configuration_conflict' : ($available ? 'observed' : 'unavailable'),
                'total_bytes' => $total,
                'free_bytes' => $free,
                'reserve_bytes' => $reserve,
                'headroom_bytes' => $free === null ? null : max(0, $free - $reserve),
            ];
        }

        return ['schema_version' => 1, 'read_only' => true, 'write_admission_enabled' => false, 'locations' => $rows, 'capacity_groups' => $pools];
    }
}
