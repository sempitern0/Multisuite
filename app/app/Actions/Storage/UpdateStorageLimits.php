<?php

namespace App\Actions\Storage;

use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Storage\StorageReservations;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Changes a person's quota, a disk limit or the default quota, with audit.
 * Domain refusals become a validation message on `$field` (next to the form that
 * caused them). Shared by StorageController and the Livewire page.
 */
class UpdateStorageLimits
{
    public function __construct(private readonly StorageReservations $reservations) {}

    public function userQuota(User $actor, User $target, int $bytes, string $field): void
    {
        $this->guard($field, function () use ($actor, $target, $bytes): void {
            DB::transaction(function () use ($actor, $target, $bytes): void {
                [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
                Gate::forUser($actor)->authorize('manage-storage-quota', $target);
                $previous = $this->reservations->setUserQuota($target, $bytes);
                if ($previous !== $bytes) {
                    app(AdminAudit::class)->recordStorage($actor, $target, 'storage.user_quota', null, $bytes, $previous);
                }
            });
        });
    }

    public function poolLimit(User $actor, string $pool, int $bytes, string $field): void
    {
        Gate::forUser($actor)->authorize('manage-storage-pools');
        $this->guard($field, function () use ($actor, $pool, $bytes): void {
            $previous = $this->reservations->setPoolLimit($pool, $bytes);
            if ($previous !== $bytes) {
                app(AdminAudit::class)->recordStorage($actor, $actor, 'storage.pool_limit', $pool, $bytes, $previous);
            }
        });
    }

    public function defaultQuota(User $actor, int $bytes, string $field): void
    {
        Gate::forUser($actor)->authorize('manage-storage-pools');
        $this->guard($field, function () use ($actor, $bytes): void {
            $previous = $this->reservations->setDefaultUserQuota($bytes);
            if ($previous !== $bytes) {
                app(AdminAudit::class)->recordStorage($actor, $actor, 'storage.default_quota', null, $bytes, $previous);
            }
        });
    }

    private function guard(string $field, callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException|InvalidArgumentException $exception) {
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
