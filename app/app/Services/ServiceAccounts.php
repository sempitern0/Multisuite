<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ServiceAccounts
{
    /** @return list<string> */
    public function available(): array
    {
        return config('portal.services.music.enabled') && config('provisioning.music.enabled') ? ['music'] : [];
    }

    /** @param list<string> $services */
    public function enroll(User $user, array $services, #[\SensitiveParameter] string $password): void
    {
        foreach ($services as $service) {
            $account = new ServiceAccount;
            $account->user_id = $user->id;
            $account->service = $service;
            $account->remote_id = (string) Str::uuid();
            $account->username = $user->email;
            $this->stage($account, $password);
        }
    }

    public function changePassword(User $user, #[\SensitiveParameter] string $password): void
    {
        DB::transaction(function () use ($user, $password): void {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $initialActivation = $locked->must_change_password;
            $locked->password = $password;
            $locked->must_change_password = false;
            $locked->save();
            if ($initialActivation) {
                $this->enroll($locked, $locked->service_access ?? [], $password);
            }
            foreach (ServiceAccount::where('user_id', $user->id)->lockForUpdate()->get() as $account) {
                $this->stage($account, $password);
            }
        });
        $user->refresh();
        $this->syncUser($user);
    }

    private function stage(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $account->pending_password = $password;
        $account->credential_expires_at = now()->addHour();
        $account->retry_at = now();
        $account->status = 'pending';
        $account->save();
    }

    public function syncUser(User $user): void
    {
        foreach (ServiceAccount::where('user_id', $user->id)->pluck('id') as $id) {
            $this->synchronize((int) $id);
        }
    }

    public function synchronize(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $account = ServiceAccount::whereKey($id)->lockForUpdate()->first();
            if ($account === null || $account->pending_password === null) {
                return;
            }
            if ($account->credential_expires_at === null || $account->credential_expires_at->isPast()) {
                $account->pending_password = null;
                $account->credential_expires_at = null;
                $account->status = 'expired';
                $account->save();

                return;
            }
            try {
                if ($account->service !== 'music' || ! in_array('music', $this->available(), true)) {
                    throw new RuntimeException('configuration');
                }
                app(NavidromeAccounts::class)->synchronize($account, $account->pending_password);
                $account->status = 'ready';
                $account->pending_password = null;
                $account->credential_expires_at = null;
                $account->retry_at = null;
            } catch (RuntimeException $exception) {
                $account->status = in_array($exception->getMessage(), ['conflict', 'configuration'], true)
                    ? $exception->getMessage() : 'pending';
                $account->retry_at = now()->addMinutes(5);
            }
            $account->save();
        });
    }
}
