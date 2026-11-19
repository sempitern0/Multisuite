<?php

namespace App\Services;

use App\Enums\ServiceAuditAction;
use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ServiceProvisioner;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

final class KomgaAccounts implements PersonalLibraries, ServiceProvisioner
{
    public static function connected(): bool
    {
        return ServiceConnection::whereKey('komga')->exists();
    }

    public function http(): PendingRequest
    {
        return Http::baseUrl(ServiceUrl::validated((string) config('portal.komga_internal_url')))
            ->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(2)->timeout(8);
    }

    public function client(): PendingRequest
    {
        $connection = ServiceConnection::find('komga') ?? throw new RuntimeException('configuration');

        return $this->http()->withHeaders(['X-API-Key' => $connection->secret]);
    }

    /** Authorise the integration once, proving possession of the existing native administrator. */
    public function connect(User $owner, string $email, #[\SensitiveParameter] string $password): void
    {
        abort_unless($owner->role === 'owner' && ! $owner->isSuspended() && $owner->hasVerifiedEmail(), 403);
        Cache::lock('komga-connection', 60)->block(10, function () use ($owner, $email, $password): void {
            $this->guard(function () use ($owner, $email, $password): void {
                $http = $this->http();
                $claim = $this->check($http->get('/api/v1/claim'))->json('isClaimed');
                if ($claim === false) {
                    $this->check($this->http()->withHeaders(['X-Komga-Email' => $email, 'X-Komga-Password' => $password])
                        ->post('/api/v1/claim'));
                }
                $native = $this->check($this->http()->withBasicAuth($email, $password)->get('/api/v2/users/me'))->json();
                if (! is_array($native) || ! in_array('ADMIN', $native['roles'] ?? [], true)) {
                    throw new RuntimeException('configuration');
                }
                $key = $this->check($this->http()->withBasicAuth($email, $password)
                    ->post('/api/v2/users/me/api-keys', ['comment' => 'Multisuite '.Str::uuid()]))->json('key');
                if (! is_string($key) || $key === '') {
                    throw new RuntimeException('unavailable');
                }
                DB::transaction(function () use ($owner, $native, $key): void {
                    $locked = User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
                    abort_unless($locked->role === 'owner' && ! $locked->isSuspended(), 403);
                    $connection = ServiceConnection::find('komga') ?? new ServiceConnection;
                    $connection->service = 'komga';
                    $connection->operator_id = (string) $native['id'];
                    $connection->secret = $key;
                    $connection->save();
                    // Explicit credential proof links the owner's existing account, even if its email differs.
                    $account = ServiceAccount::where('user_id', $locked->id)->where('service', 'komga')->first()
                        ?? app(ServiceAccounts::class)->reserve($locked, 'komga');
                    if ($account->external_id !== null && $account->external_id !== $native['id']) {
                        throw new RuntimeException('conflict');
                    }
                    $account->external_id = (string) $native['id'];
                    $account->remote_email = (string) $native['email'];
                    $account->status = ServiceAccounts::AWAITING_PASSWORD;
                    $account->save();
                    if ($locked->service_access !== null) {
                        $locked->service_access = array_values(array_unique([...$locked->service_access, 'komga']));
                        $locked->services_revoked = array_values(array_diff($locked->services_revoked ?? [], ['komga']));
                        $locked->save();
                    }
                    app(AdminAudit::class)->recordService($locked, $locked, 'komga', ServiceAuditAction::AccountLinked);
                });
            });
        });
        $this->reserveExisting();
    }

    /** No passwords can be recovered from Laravel hashes: existing users activate on their next login. */
    public function reserveExisting(): void
    {
        if (! config('provisioning.komga.enabled') || ! self::connected()) {
            return;
        }
        User::orderBy('id')->each(function (User $user): void {
            DB::transaction(function () use ($user): void {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $accounts = app(ServiceAccounts::class);
                $accounts->grantNewServices($locked);
                $accounts->awaitPassword($locked);
            });
        });
    }

    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $this->guard(function () use ($account, $password): void {
            $user = User::findOrFail($account->user_id);
            $remote = $this->find($account);
            if ($remote === null) {
                if ($account->external_id !== null) {
                    throw new RuntimeException('conflict');
                }
                $email = $user->email;
                $sameEmail = collect((array) $this->check($this->client()->get('/api/v2/users'))->json())
                    ->first(fn ($row) => strcasecmp((string) $row['email'], $email) === 0);
                if ($sameEmail !== null) {
                    // Never reset or adopt a native account merely because its email matches.
                    $proof = $this->http()->withBasicAuth($email, $password)->get('/api/v2/users/me');
                    if (! $proof->successful() || $proof->json('id') !== $sameEmail['id']
                        || in_array('ADMIN', $sameEmail['roles'], true)) {
                        throw new RuntimeException('conflict');
                    }
                    $remote = $sameEmail;
                } else {
                    $remote = $this->check($this->client()->post('/api/v2/users', [
                        'email' => $email, 'password' => $password,
                        'roles' => ['FILE_DOWNLOAD', 'PAGE_STREAMING'],
                        'sharedLibraries' => ['all' => false, 'libraryIds' => []],
                    ]))->json();
                }
                $account->external_id = (string) $remote['id'];
                $account->remote_email = (string) $remote['email'];
            }
            $this->assertIdentity($account, $remote);
            $this->check($this->client()->patch('/api/v2/users/'.$account->external_id.'/password', ['password' => $password]));
            // Both folders and permissions are ready before login; an empty account never gets all libraries.
            foreach (['libros' => 'Mis libros', 'comics' => 'Mis cómics'] as $section => $label) {
                app(MediaLibrary::class)->directory('komga', $account, $section);
                $this->library($account, $section, $label);
            }
            $this->permissions($account);
            $login = $this->check($this->http()->withBasicAuth((string) $account->remote_email, $password)->get('/api/v2/users/me'));
            if ($login->json('id') !== $account->external_id) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function rename(ServiceAccount $account, string $username): void
    {
        // Komga signs in by remote_email. Portal usernames can change without changing that identity.
    }

    public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $this->guard(function () use ($account, $section, $label): void {
            if (! in_array($section, ['libros', 'comics'], true)) {
                throw new RuntimeException('configuration');
            }
            DB::transaction(function () use ($account, $section, $label): void {
                // Serialize against suspension/revocation: a stale media job must never restore access.
                $locked = ServiceAccount::whereKey($account->id)->lockForUpdate()->first();
                $user = $locked === null ? null : User::find($locked->user_id);
                if ($locked === null || $locked->status !== 'ready' || $user === null
                    || ! app(ServiceAccounts::class)->entitled($user, 'komga')) {
                    return;
                }
                $this->library($locked, $section, $label);
                $this->permissions($locked);
                $locked->save();
                $this->check($this->client()->post('/api/v1/libraries/'.$locked->library_ids[$section].'/scan'));
            });
        });
    }

    public function lock(ServiceAccount $account): void
    {
        $this->guard(function () use ($account): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote);
            if (in_array('ADMIN', $remote['roles'], true)) {
                throw new RuntimeException('configuration');
            }
            $this->check($this->client()->patch('/api/v2/users/'.$account->external_id, [
                'roles' => [], 'sharedLibraries' => ['all' => false, 'libraryIds' => []],
            ]));
            $this->check($this->client()->patch('/api/v2/users/'.$account->external_id.'/password', ['password' => Str::random(64)]));
            $locked = $this->find($account);
            if ($locked === null || $locked['sharedAllLibraries'] !== false || $locked['sharedLibrariesIds'] !== []
                || in_array('ADMIN', $locked['roles'], true) || in_array('PAGE_STREAMING', $locked['roles'], true)) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function remove(ServiceAccount $account): void
    {
        $this->guard(function () use ($account): void {
            $remote = $this->find($account);
            if ($remote === null) {
                return;
            }
            $this->assertIdentity($account, $remote);
            if (in_array('ADMIN', $remote['roles'], true)) {
                throw new RuntimeException('configuration');
            }
            $this->check($this->client()->delete('/api/v2/users/'.$account->external_id));
            if ($this->find($account) !== null) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function find(ServiceAccount $account): ?array
    {
        if ($account->external_id === null) {
            return null;
        }
        foreach ($this->check($this->client()->get('/api/v2/users'))->json() as $remote) {
            if ($remote['id'] === $account->external_id) {
                return $remote;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $remote */
    private function assertIdentity(ServiceAccount $account, array $remote): void
    {
        if ($remote['id'] !== $account->external_id || $remote['email'] !== $account->remote_email) {
            throw new RuntimeException('conflict');
        }
        // Check privilege drift before any password or library mutation, not only before login.
        if (in_array('ADMIN', $remote['roles'], true)
            && (User::find($account->user_id)?->role !== 'owner'
                || ServiceConnection::find('komga')?->operator_id !== $account->external_id)) {
            throw new RuntimeException('conflict');
        }
    }

    private function library(ServiceAccount $account, string $section, string $label): void
    {
        $relative = ($account->media_id ?? $account->remote_id).'/'.$section;
        $root = '/personal/'.$relative;
        $libraries = $this->check($this->client()->get('/api/v1/libraries'))->json();
        $library = collect((array) $libraries)->first(fn ($row) => in_array(rtrim((string) $row['root'], '/'), [$root, '/data/'.$relative], true));
        if ($library === null) {
            $library = $this->check($this->client()->post('/api/v1/libraries', [
                'name' => $label.' · '.substr($account->remote_id, 0, 8), 'root' => $root,
                'scanOnStartup' => true, 'repairExtensions' => false, 'convertToCbz' => false,
                'scanEpub' => true, 'scanPdf' => true, 'scanCbx' => true,
            ]))->json();
        }
        $ids = $account->library_ids ?? [];
        $ids[$section] = (string) $library['id'];
        $account->library_ids = $ids;
    }

    private function permissions(ServiceAccount $account): void
    {
        $remote = $this->find($account) ?? throw new RuntimeException('unavailable');
        $this->assertIdentity($account, $remote);
        if (in_array('ADMIN', $remote['roles'], true)) {
            // assertIdentity already verified the explicitly connected owner.
            return;
        }
        $ids = array_values($account->library_ids ?? []);
        sort($ids);
        $existing = $remote['sharedLibrariesIds'];
        sort($existing);
        if ($remote['sharedAllLibraries'] === false && $existing === $ids
            && in_array('PAGE_STREAMING', $remote['roles'], true) && ! in_array('ADMIN', $remote['roles'], true)) {
            return;
        }
        $this->check($this->client()->patch('/api/v2/users/'.$account->external_id, [
            'roles' => ['FILE_DOWNLOAD', 'PAGE_STREAMING'],
            'sharedLibraries' => ['all' => false, 'libraryIds' => $ids],
        ]));
        $verified = $this->find($account) ?? throw new RuntimeException('unavailable');
        $actual = $verified['sharedLibrariesIds'];
        sort($actual);
        if ($verified['sharedAllLibraries'] !== false || $actual !== $ids || in_array('ADMIN', $verified['roles'], true)) {
            throw new RuntimeException('unavailable');
        }
    }

    private function check(Response $response): Response
    {
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException('configuration');
        }
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }

        return $response;
    }

    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }
}
