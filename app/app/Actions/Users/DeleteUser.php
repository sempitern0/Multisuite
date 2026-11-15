<?php

namespace App\Actions\Users;

use App\Models\Invitation;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Provisioning\ServiceProvisioners;
use App\Services\Storage\StorageReservations;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/** Permanently removes a person and their linked service accounts, or nothing at all. */
class DeleteUser
{
    public function handle(User $actor, User $target, string $confirmEmail): void
    {
        DB::transaction(function () use ($actor, $target, $confirmEmail): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('delete', $target);
            if ($confirmEmail !== $target->email) {
                throw ValidationException::withMessages(['confirm_email' => 'Escribe el correo de esta cuenta para confirmar.']);
            }
            app(StorageReservations::class)->prepareUserDeletion($target);
            $accounts = $target->serviceAccounts()->orderBy('id')->lockForUpdate()->get();
            $provisioners = app(ServiceProvisioners::class);
            if ($accounts->contains(fn ($account) => ! $provisioners->supports($account->service))) {
                throw ValidationException::withMessages(['deletion' => 'Esta cuenta tiene un servicio cuya baja todavía no está automatizada.']);
            }
            $service = null;
            try {
                foreach ($accounts as $account) {
                    $service = $account->service;
                    $provisioners->for($account->service)->remove($account);
                }
            } catch (RuntimeException) {
                $name = config('portal.services.'.($service ?? '').'.name', 'el servicio');
                throw ValidationException::withMessages(['deletion' => 'No se pudo confirmar la baja en '.$name.'. Revisa la conexión y vuelve a intentarlo. La cuenta de Multisuite se ha conservado.']);
            }
            Password::broker()->deleteToken($target);
            Invitation::where('email', $target->email)->update(['expires_at' => now()]);
            $target->delete();
            app(AdminAudit::class)->record($actor, $target, 'user.deleted');
        });
    }
}
