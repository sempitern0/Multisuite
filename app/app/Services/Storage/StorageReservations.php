<?php

namespace App\Services\Storage;

use App\Models\StoragePool;
use App\Models\StorageReservation;
use App\Models\StorageSetting;
use App\Models\User;
use App\Models\UserStorageQuota;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StorageReservations
{
    // Bound arithmetic and reject accidental floats/scientific notation at entry points.
    public const MAX_BYTES = 1_000_000_000_000_000;

    public function __construct(private StorageInventory $inventory) {}

    public function reserve(User $user, string $poolId, int $bytes, string $key): StorageReservation
    {
        $bytes = $this->validateBytes($bytes, false);
        if (! Str::isUuid($key)) {
            throw new RuntimeException('La clave de operación debe ser un UUID.');
        }

        return DB::transaction(function () use ($user, $poolId, $bytes, $key): StorageReservation {
            // Same order for all mutations: user, pool, quota, reservation.
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $pool = StoragePool::whereKey($poolId)->lockForUpdate()->firstOrFail();
            // The user row is locked, so lazily creating the default quota cannot race.
            $quota = UserStorageQuota::whereKey($user->id)->lockForUpdate()->first() ?? $this->createDefaultQuota($user);
            $existing = StorageReservation::whereKey($key)->lockForUpdate()->first();
            if ($existing !== null) {
                $this->owns($existing, $user);
                if ($existing->pool_id !== $poolId || $existing->bytes !== $bytes) {
                    throw new RuntimeException('La clave ya corresponde a otra operación.');
                }

                // Return the same lifecycle, never allocate again for a terminal/expired key.
                return $existing;
            }
            $headroom = $this->headroom($poolId);
            if ($bytes > $quota->limit_bytes - $quota->used_bytes - $quota->reserved_bytes
                || $bytes > $pool->limit_bytes - $pool->used_bytes - $pool->reserved_bytes
                || $bytes > $headroom - $pool->reserved_bytes) {
                throw new RuntimeException('Cuota o espacio disponible insuficiente.');
            }
            $pool->reserved_bytes = $this->nonnegative($pool->reserved_bytes + $bytes);
            $quota->reserved_bytes = $this->nonnegative($quota->reserved_bytes + $bytes);
            $pool->save();
            $quota->save();
            $reservation = new StorageReservation;
            $reservation->id = $key;
            $reservation->user_id = $this->nonnegative($user->id);
            $reservation->pool_id = $poolId;
            $reservation->bytes = $bytes;
            $reservation->state = 'reserved';
            $reservation->expires_at = now()->addMinutes(30);
            $reservation->save();

            return $reservation;
        });
    }

    // Trusted finalizer only: caller must already have verified actual bytes on disk.
    public function commit(User $user, string $key, int $actualBytes): StorageReservation
    {
        $actualBytes = $this->validateBytes($actualBytes, true);

        return $this->transition($user, $key, 'committed', $actualBytes);
    }

    // Trusted cleanup only: caller must establish no writer or temporary file remains.
    public function release(User $user, string $key): StorageReservation
    {
        return $this->transition($user, $key, 'released', null);
    }

    private function transition(User $user, string $key, string $state, ?int $actual): StorageReservation
    {
        return DB::transaction(function () use ($user, $key, $state, $actual): StorageReservation {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lookup = StorageReservation::whereKey($key)->firstOrFail();
            $this->owns($lookup, $user);
            $pool = StoragePool::whereKey($lookup->pool_id)->lockForUpdate()->firstOrFail();
            $quota = UserStorageQuota::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $reservation = StorageReservation::whereKey($key)->lockForUpdate()->firstOrFail();
            if ($reservation->state === $state && $reservation->actual_bytes === $actual) {
                return $reservation;
            }
            if ($reservation->state !== 'reserved') {
                throw new RuntimeException('La operación ya está finalizada.');
            }
            if ($state === 'committed' && ($reservation->expires_at->isPast() || $actual === null || $actual > $reservation->bytes)) {
                throw new RuntimeException('Reserva caducada o tamaño final fuera de la reserva.');
            }
            if ($pool->reserved_bytes < $reservation->bytes || $quota->reserved_bytes < $reservation->bytes) {
                throw new RuntimeException('Contabilidad inconsistente: requiere reconciliación.');
            }
            $pool->reserved_bytes = $this->nonnegative($pool->reserved_bytes - $reservation->bytes);
            $quota->reserved_bytes = $this->nonnegative($quota->reserved_bytes - $reservation->bytes);
            $pool->used_bytes = $this->nonnegative($pool->used_bytes + ($actual ?? 0));
            $quota->used_bytes = $this->nonnegative($quota->used_bytes + ($actual ?? 0));
            $pool->save();
            $quota->save();
            $reservation->state = $state;
            $reservation->actual_bytes = $actual === null ? null : $this->nonnegative($actual);
            $reservation->save();

            return $reservation;
        });
    }

    /** @return int|null Previous explicit limit, null when the user followed the default. */
    /**
     * Returns confirmed bytes to the quota and the pool. Only the purge step calls it,
     * after verifying that the blob no longer exists. Repeating it is a no-op.
     */
    public function releaseCommitted(User $user, string $key): StorageReservation
    {
        return DB::transaction(function () use ($user, $key): StorageReservation {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $lookup = StorageReservation::whereKey($key)->firstOrFail();
            $this->owns($lookup, $user);
            $pool = StoragePool::whereKey($lookup->pool_id)->lockForUpdate()->firstOrFail();
            $quota = UserStorageQuota::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $reservation = StorageReservation::whereKey($key)->lockForUpdate()->firstOrFail();
            if ($reservation->state === 'purged') {
                return $reservation;
            }
            $bytes = (int) $reservation->actual_bytes;
            if ($reservation->state !== 'committed' || $pool->used_bytes < $bytes || $quota->used_bytes < $bytes) {
                throw new RuntimeException('Contabilidad inconsistente: requiere reconciliación.');
            }
            $pool->used_bytes = $this->nonnegative($pool->used_bytes - $bytes);
            $quota->used_bytes = $this->nonnegative($quota->used_bytes - $bytes);
            $pool->save();
            $quota->save();
            $reservation->state = 'purged';
            $reservation->save();

            return $reservation;
        });
    }

    public function setUserQuota(User $user, int $bytes): ?int
    {
        $bytes = $this->validateBytes($bytes, true);

        return DB::transaction(function () use ($user, $bytes): ?int {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $quota = UserStorageQuota::whereKey($user->id)->lockForUpdate()->first() ?? new UserStorageQuota;
            if ($bytes < ($quota->used_bytes ?? 0) + ($quota->reserved_bytes ?? 0)) {
                throw new RuntimeException('El límite no puede ser inferior al espacio que ya usa o tiene reservado.');
            }
            $previous = $quota->exists ? (int) $quota->limit_bytes : null;
            $quota->user_id = $this->nonnegative($user->id);
            $quota->limit_bytes = $bytes;
            $quota->save();

            return $previous;
        });
    }

    /** @return int|null Previous limit, null when the pool was not configured yet. */
    public function setPoolLimit(string $id, int $bytes): ?int
    {
        $bytes = $this->validateBytes($bytes, true);
        $this->headroom($id);

        return DB::transaction(function () use ($id, $bytes): ?int {
            // A stable configured pool is provisioned by the operator, not by uploads.
            $pool = StoragePool::whereKey($id)->lockForUpdate()->first() ?? new StoragePool;
            if ($bytes < ($pool->used_bytes ?? 0) + ($pool->reserved_bytes ?? 0)) {
                throw new RuntimeException('El límite no puede ser inferior al espacio que ya se usa o está reservado en este disco.');
            }
            $previous = $pool->exists ? (int) $pool->limit_bytes : null;
            $pool->id = $id;
            $pool->limit_bytes = $bytes;
            $pool->save();

            return $previous;
        });
    }

    public function defaultUserQuota(): int
    {
        return StorageSetting::defaultUserQuotaBytes();
    }

    /**
     * Applies only to users without an explicit quota, at their first reservation.
     * Existing explicit quotas are never rewritten implicitly.
     */
    public function setDefaultUserQuota(int $bytes): int
    {
        $bytes = $this->validateBytes($bytes, true);

        return DB::transaction(function () use ($bytes): int {
            $setting = StorageSetting::whereKey(StorageSetting::SINGLETON)->lockForUpdate()->first() ?? new StorageSetting;
            $previous = $setting->exists ? (int) $setting->default_user_quota_bytes : 0;
            $setting->id = StorageSetting::SINGLETON;
            $setting->default_user_quota_bytes = $bytes;
            $setting->save();

            return $previous;
        });
    }

    private function createDefaultQuota(User $user): UserStorageQuota
    {
        $quota = new UserStorageQuota;
        $quota->user_id = $this->nonnegative($user->id);
        $quota->limit_bytes = $this->validateBytes(StorageSetting::defaultUserQuotaBytes(), true);
        $quota->reserved_bytes = 0;
        $quota->used_bytes = 0;
        $quota->save();

        return $quota;
    }

    public function prepareUserDeletion(User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $quota = UserStorageQuota::whereKey($user->id)->lockForUpdate()->first();
            if ($quota !== null) {
                if ($quota->reserved_bytes > 0 || $quota->used_bytes > 0) {
                    throw ValidationException::withMessages(['deletion' => 'La cuenta conserva espacio reservado o contenido. Requiere reconciliación antes de eliminarla.']);
                }
                $quota->delete();
            }
        });
    }

    private function headroom(string $id): int
    {
        foreach ($this->inventory->inspect()['capacity_groups'] as $group) {
            if ($group['id'] === $id && $group['status'] === 'observed' && is_int($group['headroom_bytes'])) {
                return $group['headroom_bytes'];
            }
        }
        throw new RuntimeException('El grupo no está disponible o su capacidad es desconocida.');
    }

    private function owns(StorageReservation $reservation, User $user): void
    {
        if ($reservation->user_id !== $user->id) {
            throw new RuntimeException('Operación no disponible para este usuario.');
        }
    }

    /** @return int<0, max> */
    private function validateBytes(int $bytes, bool $zeroAllowed): int
    {
        if ($bytes < ($zeroAllowed ? 0 : 1) || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('Tamaño fuera de los límites admitidos.');
        }

        return $this->nonnegative($bytes);
    }

    /** @return int<0, max> */
    private function nonnegative(int $value): int
    {
        if ($value < 0) {
            throw new RuntimeException('Contabilidad negativa: requiere reconciliación.');
        }

        return $value;
    }
}
