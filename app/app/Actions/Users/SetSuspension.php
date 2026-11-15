<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Files\MediaLibrary;
use App\Services\ServiceAccounts;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Suspends or reactivates another person without deleting anything. Suspending blocks every
 * linked service first (if one cannot confirm, nothing changes), then closes their sessions.
 * Reactivating restores access to Multisuite; services return at their next sign-in with a password.
 */
class SetSuspension
{
    public function __construct(private ServiceAccounts $accounts, private AdminAudit $audit) {}

    public function handle(User $actor, User $target, bool $suspend): void
    {
        DB::transaction(function () use ($actor, $target, $suspend): void {
            [$actor, $target] = LockedUsers::pair($actor->id, $target->id);
            Gate::forUser($actor)->authorize('update', $target);
            if ($target->isSuspended() === $suspend) {
                return;
            }
            if ($suspend) {
                foreach ($target->serviceAccounts()->orderBy('id')->lockForUpdate()->get() as $account) {
                    try {
                        $this->accounts->lock($account);
                    } catch (RuntimeException) {
                        throw ValidationException::withMessages(['suspension' => 'No se pudo bloquear su acceso a '
                            .config('portal.services.'.$account->service.'.name', $account->service).'. Revisa la conexión y vuelve a intentarlo; la cuenta sigue activa.']);
                    }
                }
                $target->suspended_at = now();
                // Invalidates "remember me" cookies; open sessions are closed below and by EnsureAccountIsActive.
                $target->remember_token = Str::random(60);
                $target->save();
                Password::broker()->deleteToken($target);
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))->where('user_id', $target->id)->delete();
                }
            } else {
                $target->suspended_at = null;
                $target->save();
                $this->accounts->awaitPassword($target);
            }
            $this->audit->record($actor, $target, $suspend ? 'user.suspended' : 'user.reactivated');
            app(MediaLibrary::class)->changed($target);
        });
    }
}
