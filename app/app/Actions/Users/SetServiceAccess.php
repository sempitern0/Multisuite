<?php

namespace App\Actions\Users;

use App\Enums\ServiceAuditAction;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Files\MediaLibrary;
use App\Services\ServiceAccounts;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Grants or withdraws one service for another person. Withdrawing blocks sign-in in the
 * service itself (the portal hiding a card is not authorisation) while keeping their data;
 * granting again waits for their next sign-in with a password, which the portal never stores.
 */
class SetServiceAccess
{
    public function __construct(private ServiceAccounts $accounts, private AdminAudit $audit) {}

    public function handle(User $actor, User $target, string $service, bool $enabled): void
    {
        abort_unless(in_array($service, $this->accounts->available(), true), 404);
        DB::transaction(function () use ($actor, $target, $service, $enabled): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('update', $target);
            $access = $target->service_access ?? $this->accounts->available();
            if (in_array($service, $access, true) === $enabled) {
                return;
            }
            if ($enabled) {
                $target->service_access = [...$access, $service];
                $target->services_revoked = array_values(array_diff($target->services_revoked ?? [], [$service]));
                $target->save();
                $this->accounts->awaitPassword($target);
            } else {
                $account = $target->serviceAccounts()->where('service', $service)->lockForUpdate()->first();
                if ($account !== null) {
                    try {
                        $this->accounts->lock($account);
                    } catch (RuntimeException) {
                        throw ValidationException::withMessages(['services' => 'No se pudo desactivar en '
                            .config('portal.services.'.$service.'.name', $service).'. Revisa la conexión y vuelve a intentarlo; el acceso se mantiene.']);
                    }
                }
                $target->service_access = array_values(array_diff($access, [$service]));
                // Remembered so the next sign-in does not grant it back as a "new" service.
                $target->services_revoked = array_values(array_unique([...($target->services_revoked ?? []), $service]));
                $target->save();
            }
            $this->audit->recordService($actor, $target, $service, $enabled ? ServiceAuditAction::AccessGranted : ServiceAuditAction::AccessRevoked);
            // Connected folders follow access: emptied in the service when withdrawn, refilled when granted.
            app(MediaLibrary::class)->changed($target);
        });
    }
}
