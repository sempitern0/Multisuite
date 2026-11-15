<?php

namespace App\Actions\Users;

use App\Concerns\UsernameValidationRules;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Provisioning\ServiceProvisioners;
use App\Services\ServiceAccounts;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Changes another person's username everywhere at once: services first (remote rename keeps
 * the password and the data), then Multisuite. If a service cannot confirm, nothing changes;
 * repeating is safe because the service account is recognised by its old or new name.
 */
class ChangeUsername
{
    use UsernameValidationRules;

    public function __construct(private ServiceAccounts $accounts, private ServiceProvisioners $provisioners, private AdminAudit $audit) {}

    /** @return array<string, array<int, mixed>> */
    public function rules(User $target): array
    {
        return ['username' => ['required', ...$this->usernameRules($target->id)]];
    }

    public function handle(User $actor, User $target, string $username): void
    {
        DB::transaction(function () use ($actor, $target, $username): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('update', $target);
            if ($target->username === $username) {
                return;
            }
            if (ServiceAccount::where('username', $username)->where('user_id', '!=', $target->id)->exists()) {
                throw ValidationException::withMessages(['username' => 'Ese nombre de usuario ya lo usa otra cuenta de un servicio.']);
            }
            $available = $this->accounts->available();
            foreach ($target->serviceAccounts()->orderBy('id')->lockForUpdate()->get() as $account) {
                // A service that is switched off is renamed at its next synchronisation instead.
                if (! in_array($account->service, $available, true)) {
                    continue;
                }
                $name = config('portal.services.'.$account->service.'.name', $account->service);
                try {
                    $this->provisioners->for($account->service)->rename($account, $username);
                } catch (RuntimeException $exception) {
                    throw ValidationException::withMessages(['username' => $exception->getMessage() === 'conflict'
                        ? 'Ese nombre de usuario ya existe en '.$name.'. Elige otro.'
                        : 'No se pudo cambiar en '.$name.'. Revisa la conexión y vuelve a intentarlo; no se ha cambiado nada.']);
                }
                $account->username = $username;
                $account->save();
            }
            $target->username = $username;
            $target->save();
            $this->audit->record($actor, $target, 'user.username_changed', ['username']);
        });
    }
}
