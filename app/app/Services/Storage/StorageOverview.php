<?php

namespace App\Services\Storage;

use App\Models\StoragePool;
use App\Models\StorageReservation;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Support\Bytes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Read model for the administrative storage page. It never accepts paths and never
 * writes: limits change only through StorageReservations.
 */
class StorageOverview
{
    private const STATUS_LABELS = [
        'observed' => 'Conectado',
        'unavailable' => 'No disponible: desconectado, sin marca de identidad o sin permisos',
        'configuration_conflict' => 'Configuración en conflicto: dos grupos apuntan al mismo disco',
        'missing' => 'Ya no figura en la configuración del servidor',
    ];

    public function __construct(private StorageInventory $inventory, private StorageReservations $reservations, private HardwareSnapshot $hardware) {}

    /** @return array<string, mixed> */
    public function build(User $viewer, string $query): array
    {
        $inventoryError = null;
        $groups = [];
        $locations = [];
        try {
            $report = $this->inventory->inspect();
            $groups = $report['capacity_groups'];
            $locations = $report['locations'];
        } catch (InvalidArgumentException $exception) {
            $inventoryError = $exception->getMessage();
        }

        $pools = StoragePool::all()->keyBy('id');
        $stale = $this->staleReservations();
        $disks = [];
        foreach ($groups as $group) {
            $disks[] = $this->disk($group, $pools->get($group['id']), $stale[$group['id']] ?? [], array_values(array_map(
                fn (array $location): string => $location['id'],
                array_filter($locations, fn (array $location): bool => $location['group'] === $group['id']),
            )));
        }
        $listed = array_column($disks, 'id');
        foreach ($pools as $id => $pool) {
            if (! in_array($id, $listed, true)) {
                $disks[] = $this->disk(['id' => (string) $id, 'status' => 'missing'], $pool, $stale[$id] ?? [], []);
            }
        }

        $default = $this->reservations->defaultUserQuota();
        $quotas = UserStorageQuota::all()->keyBy('user_id');
        $userCount = User::count();
        $poolLimitTotal = (int) $pools->sum('limit_bytes');
        $assigned = (int) $quotas->sum('limit_bytes') + $default * max(0, $userCount - $quotas->count());

        $users = User::query()->search($query)->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();

        return [
            'inventoryError' => $inventoryError,
            'configured' => $locations !== [],
            'disks' => $disks,
            'default' => $default,
            'assigned' => $assigned,
            'poolLimitTotal' => $poolLimitTotal,
            'usedTotal' => (int) $quotas->sum('used_bytes'),
            'overcommitted' => $poolLimitTotal > 0 && $assigned > $poolLimitTotal,
            'equalShare' => $userCount > 0 && $poolLimitTotal > 0 ? intdiv($poolLimitTotal, $userCount) : null,
            'userCount' => $userCount,
            'users' => $users,
            'rows' => $this->rows($viewer, $users, $quotas->all(), $default),
            'query' => $query,
            'canManagePools' => Gate::forUser($viewer)->allows('manage-storage-pools'),
            'virtualHost' => self::runsInDesktopVm(),
            'hardware' => $this->hardware->read(),
            'presets' => Bytes::presets(),
            'units' => array_keys(Bytes::UNITS),
        ];
    }

    /**
     * Docker Desktop (Windows/macOS) runs containers in a utility VM whose volumes live
     * in a sparse virtual disk: statvfs reports that disk (often ~1 TB), not the host's
     * physical drive. On a Linux server with bind-mounted disks the figures are exact.
     */
    public static function runsInDesktopVm(?string $kernelRelease = null): bool
    {
        $release = strtolower($kernelRelease ?? php_uname('r'));

        return str_contains($release, 'microsoft-standard') || str_contains($release, 'linuxkit');
    }

    /**
     * Expired reservations still count against quotas until a verified cleanup releases them.
     * Those with a pending private upload are handled by portal:uploads-cleanup; the rest
     * have no automatic owner and need the operator's reconciliation.
     *
     * @return array<string, array{automatic: int, manual: int, count: int}>
     */
    private function staleReservations(): array
    {
        // One aggregate in SQL instead of loading every pending upload id into PHP.
        $rows = StorageReservation::query()
            ->leftJoin('private_uploads', function ($join): void {
                $join->on('private_uploads.id', '=', 'storage_reservations.id')->where('private_uploads.state', '=', 'pending');
            })
            ->where('storage_reservations.state', 'reserved')
            ->where('storage_reservations.expires_at', '<', now())
            ->groupBy('storage_reservations.pool_id')
            ->selectRaw('storage_reservations.pool_id as pool_id')
            ->selectRaw('coalesce(sum(case when private_uploads.id is not null then storage_reservations.bytes else 0 end), 0) as automatic')
            ->selectRaw('coalesce(sum(case when private_uploads.id is null then storage_reservations.bytes else 0 end), 0) as manual')
            ->selectRaw('count(*) as stale_count')
            ->toBase()->get();
        $stale = [];
        foreach ($rows as $row) {
            $stale[(string) $row->pool_id] = ['automatic' => (int) $row->automatic, 'manual' => (int) $row->manual, 'count' => (int) $row->stale_count];
        }

        return $stale;
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array{automatic?: int, manual?: int, count?: int}  $stale
     * @param  list<string>  $locationIds
     * @return array<string, mixed>
     */
    private function disk(array $group, ?StoragePool $pool, array $stale, array $locationIds): array
    {
        $status = (string) $group['status'];
        $observed = $status === 'observed';
        $used = (int) ($pool->used_bytes ?? 0);
        $reserved = (int) ($pool->reserved_bytes ?? 0);
        $metric = fn (string $key): ?int => $observed && isset($group[$key]) && is_int($group[$key]) ? $group[$key] : null;
        $total = $metric('total_bytes');
        $free = $metric('free_bytes');
        $margin = $metric('reserve_bytes') ?? 0;
        $headroom = $metric('headroom_bytes');
        // Largest limit the portal can really honour: what it already stores plus physical headroom.
        $suggested = $headroom === null ? null : $used + $headroom;
        $limit = $pool === null ? null : (int) $pool->limit_bytes;

        $segments = [];
        if ($total !== null && $free !== null && $total > 0) {
            $portal = min($used, $total);
            $other = max(0, $total - $free - $portal);
            $safety = min($margin, $free);
            $available = max(0, $free - $safety);
            // Colours live in the Blade view (Tailwind scans views), keyed by segment.
            foreach ([
                ['portal', 'Contenido del portal', $portal],
                ['other', 'Otros datos del disco', $other],
                ['safety', 'Margen de seguridad', $safety],
                ['free', 'Libre', $available],
            ] as [$key, $label, $bytes]) {
                $segments[] = ['key' => $key, 'label' => $label, 'bytes' => $bytes, 'percent' => Bytes::percent($bytes, $total)];
            }
        }

        return [
            'id' => (string) $group['id'],
            'status' => $status,
            'statusLabel' => self::STATUS_LABELS[$status] ?? 'Estado desconocido',
            'locations' => $locationIds,
            'total' => $total,
            'free' => $free,
            'margin' => $margin,
            'headroom' => $headroom,
            'limit' => $limit,
            'used' => $used,
            'reserved' => $reserved,
            'suggested' => $suggested,
            'segments' => $segments,
            'limitAboveCapacity' => $limit !== null && $suggested !== null && $limit > $suggested,
            'editable' => $observed,
            'staleAutomatic' => $stale['automatic'] ?? 0,
            'staleManual' => $stale['manual'] ?? 0,
            'staleCount' => $stale['count'] ?? 0,
        ];
    }

    /**
     * @param  LengthAwarePaginator<int, User>  $users
     * @param  array<int|string, UserStorageQuota>  $quotas
     * @return list<array<string, mixed>>
     */
    private function rows(User $viewer, LengthAwarePaginator $users, array $quotas, int $default): array
    {
        $rows = [];
        foreach ($users->items() as $user) {
            $quota = $quotas[$user->id] ?? null;
            $limit = $quota === null ? $default : (int) $quota->limit_bytes;
            $used = (int) ($quota->used_bytes ?? 0);
            $reserved = (int) ($quota->reserved_bytes ?? 0);
            $rows[] = [
                'user' => $user,
                'followsDefault' => $quota === null,
                'limit' => $limit,
                'used' => $used,
                'reserved' => $reserved,
                'percent' => Bytes::percent($used + $reserved, $limit),
                'canManage' => Gate::forUser($viewer)->allows('manage-storage-quota', $user),
            ];
        }

        return $rows;
    }
}
