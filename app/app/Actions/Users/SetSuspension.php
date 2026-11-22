<?php

namespace App\Actions\Users;

use App\Models\Invitation;
use App\Models\User;
use App\Services\AdminAudit;
use App\Services\Files\MediaLibrary;
use App\Services\ServiceAccounts;
use App\Support\LockedUsers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Commit local suspension and persist remote work for the account scheduler. */
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
                $target->serviceAccounts()->where('status', '!=', ServiceAccounts::LOCKED)->update([
                    'status' => ServiceAccounts::LOCK_PENDING, 'pending_password' => null,
                    'credential_expires_at' => null, 'retry_at' => now(),
                ]);
                Invitation::where('invited_by', $target->id)->whereNull('used_at')->update(['expires_at' => now()]);
                $tokens = DB::table('oauth_access_tokens')->where('user_id', $target->id);
                DB::table('oauth_refresh_tokens')->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
                $tokens->update(['revoked' => true]);
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
