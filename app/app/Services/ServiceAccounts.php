<?php

namespace App\Services;

use App\Enums\ServiceAccountStatus;
use App\Enums\ServiceAuditAction;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\Provisioning\ServiceProvisioners;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

use function Illuminate\Support\defer;

/**
 * Local side of the accounts each person has in third-party services. Passwords are only
 * held encrypted, for one hour, while they are pushed to the service (docs/SERVICE_ACCOUNTS.md).
 */
class ServiceAccounts
{
    /** Disabled or suspended: the remote password was replaced and is waiting for access to return. */
    public const LOCKED = ServiceAccountStatus::Locked->value;

    /** Access granted again (or for the first time): the next sign-in with a password activates it. */
    public const AWAITING_PASSWORD = ServiceAccountStatus::AwaitingPassword->value;

    public function __construct(private ServiceProvisioners $provisioners) {}

    /** @return list<string> */
    public function available(): array
    {
        /** @var array<string, mixed> $services */
        $services = config('portal.services', []);

        return array_values(array_filter(
            array_map(strval(...), array_keys($services)),
            fn (string $service): bool => config('portal.services.'.$service.'.enabled')
                && config('provisioning.'.$service.'.enabled')
                && $this->provisioners->supports($service),
        ));
    }

    /** May the person use the service now? A null access list predates per-service access and grants everything. */
    public function entitled(User $user, string $service): bool
    {
        return $user->suspended_at === null
            && ($user->service_access === null || in_array($service, $user->service_access, true));
    }

    /**
     * @param  list<string>  $services
     * @return list<string>
     */
    public function currentServices(array $services): array
    {
        if (in_array('komga', $this->available(), true) && ! config('portal.services.reading.enabled')) {
            $services = array_map(fn (string $service): string => $service === 'reading' ? 'komga' : $service, $services);
        }

        return array_values(array_unique($services));
    }

    /** @param list<string> $services */
    public function enroll(User $user, array $services, #[\SensitiveParameter] string $password): void
    {
        foreach ($services as $service) {
            if (ServiceAccount::where('user_id', $user->id)->where('service', $service)->exists()) {
                continue;
            }
            $this->stage($this->newAccount($user, $service), $password);
        }
    }

    /** Reserves the account (remote id and name) without a password; the next sign-in activates it. */
    public function reserve(User $user, string $service): ServiceAccount
    {
        $account = $this->newAccount($user, $service);
        $account->status = self::AWAITING_PASSWORD;
        $account->save();

        return $account;
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
                $this->grantNewServices($locked);
                $this->enroll($locked, $locked->service_access ?? [], $password);
            }
            foreach (ServiceAccount::where('user_id', $user->id)->lockForUpdate()->get() as $account) {
                // A disabled service keeps its scrambled password until access is granted again.
                if ($this->entitled($locked, $account->service)) {
                    $this->stage($account, $password);
                }
            }
        });
        $user->refresh();
        $this->syncUser($user);
    }

    /**
     * Called after a successful sign-in with a password: accounts waiting for one (access granted
     * again, or a credential that expired before reaching the service) receive it. Pushed after the
     * response so signing in never waits for a third-party service.
     */
    public function resume(User $user, #[\SensitiveParameter] string $password): void
    {
        $staged = DB::transaction(function () use ($user, $password): bool {
            $locked = User::whereKey($user->id)->lockForUpdate()->first();
            if ($locked === null || $locked->must_change_password) {
                return false;
            }
            // Services added to the portal since the person last signed in join now.
            $this->grantNewServices($locked);
            $this->awaitPassword($locked);
            $accounts = ServiceAccount::where('user_id', $locked->id)
                ->whereIn('status', [self::AWAITING_PASSWORD, 'expired'])->lockForUpdate()->get()
                ->filter(fn (ServiceAccount $account): bool => $this->entitled($locked, $account->service));
            foreach ($accounts as $account) {
                $this->stage($account, $password);
            }

            return $accounts->isNotEmpty();
        });
        if ($staged) {
            $userId = $user->id;
            defer(fn () => $this->syncUser(User::findOrFail($userId)));
        }
    }

    /**
     * Grants every available service the person has never had, unless an administrator left it
     * out or turned it off ($services_revoked). Must run inside the caller's transaction with the
     * user row locked. A null access list already means "everything" and is left alone.
     *
     * @return bool whether anything was granted
     */
    public function grantNewServices(User $locked): bool
    {
        if ($locked->service_access === null || $locked->suspended_at !== null) {
            return false;
        }
        $revoked = $locked->services_revoked ?? [];
        if (in_array('reading', $revoked, true)) {
            $revoked[] = 'komga';
        }
        $new = array_values(array_diff($this->available(), $locked->service_access, $revoked));
        if ($new === []) {
            return false;
        }
        $locked->service_access = [...$locked->service_access, ...$new];
        $locked->save();
        foreach ($new as $service) {
            app(AdminAudit::class)->recordService(null, $locked, $service, ServiceAuditAction::AccessGranted);
        }

        return true;
    }

    /**
     * Services left out when an account is created or an invitation accepted: they stay out
     * until an administrator grants them.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public function notGranted(array $granted): array
    {
        return array_values(array_diff($this->available(), $granted));
    }

    /**
     * Blocks sign-in to the service (the person's data stays). Must run inside the caller's
     * transaction with the account locked; throws RuntimeException if the service cannot confirm it.
     */
    public function lock(ServiceAccount $account): void
    {
        if ($account->status !== self::LOCKED) {
            if (! in_array($account->service, $this->available(), true)) {
                throw new RuntimeException('configuration');
            }
            $this->provisioners->for($account->service)->lock($account);
        }
        $account->pending_password = null;
        $account->credential_expires_at = null;
        $account->retry_at = null;
        $account->status = self::LOCKED;
        $account->save();
    }

    /**
     * After access is granted or the account is reactivated: every service the person is now
     * entitled to waits for their next sign-in with a password. Accounts for people who have not
     * chosen their first password yet are created by changePassword() instead.
     */
    public function awaitPassword(User $locked): void
    {
        if ($locked->must_change_password || $locked->suspended_at !== null) {
            return;
        }
        $accounts = ServiceAccount::where('user_id', $locked->id)->lockForUpdate()->get()->keyBy('service');
        foreach ($this->available() as $service) {
            if (! $this->entitled($locked, $service)) {
                continue;
            }
            $account = $accounts->get($service);
            if ($account === null) {
                $this->reserve($locked, $service);
            } elseif ($account->status === self::LOCKED) {
                $account->status = self::AWAITING_PASSWORD;
                $account->save();
            }
        }
    }

    private function newAccount(User $user, string $service): ServiceAccount
    {
        $account = new ServiceAccount;
        $account->user_id = $user->id;
        $account->service = $service;
        $account->remote_id = (string) Str::uuid();
        $account->username = $user->username ?? $user->email;
        if ($service === 'komga') {
            $account->media_id = ServiceAccount::where('user_id', $user->id)->where('service', 'reading')->value('remote_id') ?? $account->remote_id;
        }

        return $account;
    }

    private function stage(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $account->pending_password = $password;
        $account->credential_expires_at = now()->addHour();
        $account->retry_at = now();
        $account->status = ServiceAccountStatus::Pending->value;
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
            $user = User::find($account->user_id);
            if ($user === null || ! $this->entitled($user, $account->service)) {
                // Access was withdrawn after the password was staged: never push it.
                $account->pending_password = null;
                $account->credential_expires_at = null;
                $account->retry_at = null;
                $account->status = self::LOCKED;
                $account->save();

                return;
            }
            if ($account->credential_expires_at === null || $account->credential_expires_at->isPast()) {
                $account->pending_password = null;
                $account->credential_expires_at = null;
                $account->status = ServiceAccountStatus::Expired->value;
                $account->save();

                return;
            }
            try {
                if (! in_array($account->service, $this->available(), true)) {
                    throw new RuntimeException('configuration');
                }
                $this->provisioners->for($account->service)->synchronize($account, $account->pending_password);
                $account->status = ServiceAccountStatus::Ready->value;
                // The account exists now: its connected folders can get their personal library.
                app(MediaLibrary::class)->changed($user);
                $account->pending_password = null;
                $account->credential_expires_at = null;
                $account->retry_at = null;
            } catch (RuntimeException $exception) {
                $account->status = in_array($exception->getMessage(), [ServiceAccountStatus::Conflict->value, ServiceAccountStatus::Configuration->value], true)
                    ? $exception->getMessage() : ServiceAccountStatus::Pending->value;
                $account->retry_at = now()->addMinutes(5);
            }
            $account->save();
        });
    }
}
