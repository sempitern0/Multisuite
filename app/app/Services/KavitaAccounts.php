<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ServiceProvisioner;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lectura (Kavita 0.9.1) accounts through its native API (contract read in the v0.9.1.4 source:
 * Kavita.Server/Controllers/AccountController.cs and UsersController.cs).
 *
 * Kavita assigns its own user ids, so each account is recognised by a synthetic address built
 * from the UUID reserved in Multisuite ({remote_id}@multisuite.invalid): a retry after a lost
 * response finds the same account, and an existing Kavita user is never adopted by matching a
 * real email. Signing in requires Kavita's "Login" role: invitations are created without it (a
 * pending invitation keeps Kavita's public default password) and it is granted only once the
 * person's password is set; locking removes it, which also revokes OPDS keys.
 */
class KavitaAccounts implements PersonalLibraries, ServiceProvisioner
{
    public const LOGIN_ROLE = 'Login';

    private const DOMAIN = 'multisuite.invalid';

    /** Read and download; never Admin, and no "Change Password": the portal owns the password. */
    private const MEMBER_ROLES = ['Download', 'Bookmark'];

    public static function identity(ServiceAccount $account): string
    {
        return strtolower($account->remote_id).'@'.self::DOMAIN;
    }

    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $user = User::findOrFail($account->user_id);
        $target = $user->username ?? $account->username;
        $this->guard(function () use ($account, $password, $target): void {
            $client = $this->client();
            $remote = $this->find($client, $account);
            if ($remote === null) {
                $this->invite($client, $account);
                $remote = $this->find($client, $account) ?? throw new RuntimeException('unavailable');
            }
            $this->assertManaged($remote);
            if ($remote['isPending'] === true) {
                $this->confirm($client, $account, $remote, $target, $password);
                $remote = $this->find($client, $account) ?? throw new RuntimeException('unavailable');
            }
            // Password first, sign-in last: if anything fails in between, a locked account never
            // comes back with its previous (possibly leaked) password.
            $this->check($client->post('/api/Account/reset-password', ['userName' => $remote['username'], 'password' => $password]));
            $this->update($client, $remote, $target, true);
            $this->verify($client, $account, $target, true);
            // Kavita answers 200 to a password reset even for unknown users: prove the new password works.
            $login = Http::baseUrl($this->url())->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->post('/api/Account/login', ['username' => $target, 'password' => $password]);
            if (! $login->successful()) {
                throw new RuntimeException('unavailable');
            }
            $account->username = $target;
        });
    }

    public function rename(ServiceAccount $account, string $username): void
    {
        $this->guard(function () use ($account, $username): void {
            $client = $this->client();
            $remote = $this->find($client, $account);
            // Missing, or still an unconfirmed invitation: the name is set when it is confirmed.
            if ($remote === null || $this->assertManaged($remote)['isPending'] === true) {
                return;
            }
            $this->update($client, $remote, $username, null);
            $this->verify($client, $account, $username, null);
        });
    }

    public function lock(ServiceAccount $account): void
    {
        $this->guard(function () use ($account): void {
            $client = $this->client();
            $remote = $this->find($client, $account);
            if ($remote === null) {
                return;
            }
            $this->assertManaged($remote);
            $this->update($client, $remote, (string) $remote['username'], false);
            $this->verify($client, $account, (string) $remote['username'], false);
        });
    }

    public function remove(ServiceAccount $account): void
    {
        $this->guard(function () use ($account): void {
            $client = $this->client();
            $remote = $this->find($client, $account);
            if ($remote === null) {
                return;
            }
            $this->assertManaged($remote);
            $client->delete('/api/Users/delete-user?'.http_build_query(['username' => $remote['username']]));
            if ($this->find($client, $account) !== null) {
                throw new RuntimeException('unavailable');
            }
        });
    }

    public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $folder = PersonalLibraries::MOUNT.'/'.strtolower($account->remote_id).($section === null ? '' : '/'.$section);
        $this->guard(function () use ($account, $section, $label, $folder): void {
            $client = $this->client();
            $remote = $this->find($client, $account);
            if ($remote === null || $this->assertManaged($remote)['isPending'] === true) {
                return;
            }
            $library = $this->libraryAt($client, $folder);
            if ($library === null) {
                $comics = $section === 'comics';
                $created = $client->post('/api/Library/create', [
                    'id' => 0,
                    // Names are unique in Kavita; the folder (not the name) identifies the library.
                    'name' => $label.' · '.$remote['username'].' · '.substr(strtolower($account->remote_id), 0, 8),
                    'type' => $comics ? 1 : 2,
                    'folders' => [$folder],
                    'folderWatching' => true,
                    'includeInDashboard' => true,
                    'includeInSearch' => true,
                    'manageCollections' => true,
                    'manageReadingLists' => true,
                    'allowScrobbling' => false,
                    'allowMetadataMatching' => false,
                    'enableMetadata' => true,
                    'removePrefixForSortName' => false,
                    'inheritWebLinksFromFirstChapter' => false,
                    'metadataProvider' => $comics ? 4 : 2,
                    'fileGroupTypes' => [1, 2, 3, 4],
                    'excludePatterns' => [],
                ]);
                $this->check($created);
                $library = $this->libraryAt($client, $folder) ?? throw new RuntimeException('unavailable');
            }
            $libraries = $this->libraryIds($remote['libraries'] ?? []);
            if (! in_array($library['id'], $libraries, true)) {
                $this->update($client, $remote, (string) $remote['username'], null, [...$libraries, (int) $library['id']]);
            }
            $this->check($client->post('/api/Library/scan?'.http_build_query(['libraryId' => $library['id'], 'force' => 'false'])));
        });
    }

    /** @return array<string, mixed>|null the library that reads exactly $folder */
    private function libraryAt(PendingRequest $client, string $folder): ?array
    {
        $response = $client->get('/api/Library/libraries');
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }
        foreach ((array) $response->json() as $library) {
            if (is_array($library) && is_int($library['id'] ?? null) && in_array($folder, (array) ($library['folders'] ?? []), true)) {
                /** @var array<string, mixed> $library */
                return $library;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $library */
    private static function isPersonal(array $library): bool
    {
        foreach ((array) ($library['folders'] ?? []) as $folder) {
            if (is_string($folder) && str_starts_with($folder, PersonalLibraries::MOUNT.'/')) {
                return true;
            }
        }

        return false;
    }

    /** Signed-in client for the operator account, which must be a Kavita administrator. */
    public function client(): PendingRequest
    {
        $http = Http::baseUrl($this->url())->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(15);
        $login = $http->post('/api/Account/login', [
            'username' => (string) config('provisioning.reading.username'),
            'password' => (string) config('provisioning.reading.password'),
        ]);
        if ($login->serverError() || $login->status() === 429) {
            throw new RuntimeException('unavailable');
        }
        $token = $login->json('token');
        if (! $login->successful() || ! is_string($token) || $token === '' || ! in_array('Admin', (array) $login->json('roles'), true)) {
            throw new RuntimeException('configuration');
        }

        return $http->withToken($token);
    }

    private function url(): string
    {
        return ServiceUrl::validated((string) config('provisioning.reading.url'));
    }

    /** Kavita reports a taken name as 400 with "username-taken"; anything else unexpected is "unavailable". */
    private function check(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }
        if ($response->status() === 400 && str_contains($response->body(), 'taken')) {
            throw new RuntimeException('conflict');
        }
        throw new RuntimeException('unavailable');
    }

    /** @return array<string, mixed>|null */
    private function find(PendingRequest $client, ServiceAccount $account): ?array
    {
        $response = $client->get('/api/Users', ['includePending' => 'true']);
        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('unavailable');
        }
        foreach ((array) $response->json() as $member) {
            if (is_array($member) && is_string($member['email'] ?? null) && strtolower($member['email']) === self::identity($account)) {
                /** @var array<string, mixed> $member */
                return $member;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @return array<string, mixed>
     */
    private function assertManaged(array $remote): array
    {
        if (in_array('Admin', (array) ($remote['roles'] ?? []), true) || ($remote['identityProvider'] ?? 0) !== 0 || ! is_string($remote['username'] ?? null)) {
            throw new RuntimeException('conflict');
        }

        return $remote;
    }

    private function invite(PendingRequest $client, ServiceAccount $account): void
    {
        $libraries = $client->get('/api/Library/libraries');
        if (! $libraries->successful()) {
            throw new RuntimeException('unavailable');
        }
        $response = $client->post('/api/Account/invite', [
            'email' => self::identity($account),
            'roles' => self::MEMBER_ROLES,
            // Shared libraries only: someone else's personal library is never granted.
            'libraries' => $this->libraryIds(array_filter((array) $libraries->json(), fn (mixed $library): bool => is_array($library) && ! self::isPersonal($library))),
            'ageRestriction' => ['ageRating' => -1, 'includeUnknowns' => true],
        ]);
        // 400 "already invited" means an earlier attempt got through: the account is found next.
        if (! $response->successful() && $response->status() !== 400) {
            throw new RuntimeException('unavailable');
        }
    }

    /** @param array<string, mixed> $remote */
    private function confirm(PendingRequest $client, ServiceAccount $account, array $remote, string $username, #[\SensitiveParameter] string $password): void
    {
        $link = $client->get('/api/Account/invite-url', ['userId' => $remote['id'], 'withBaseUrl' => 'false']);
        if (! $link->successful()) {
            throw new RuntimeException('unavailable');
        }
        $json = $link->json();
        $url = is_string($json) ? $json : $link->body();
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (! is_string($query['token'] ?? null) || $query['token'] === '') {
            throw new RuntimeException('unavailable');
        }
        $this->check($client->post('/api/Account/confirm-email', [
            'email' => self::identity($account),
            'token' => $query['token'],
            'username' => $username,
            'password' => $password,
        ]));
    }

    /**
     * Sets the name and, when $login is not null, grants or removes sign-in; $libraries replaces
     * the library list when given. Everything else is sent back unchanged (Kavita replaces the
     * whole account with what it receives).
     *
     * @param  array<string, mixed>  $remote
     * @param  list<int>|null  $libraries
     */
    private function update(PendingRequest $client, array $remote, string $username, ?bool $login, ?array $libraries = null): void
    {
        $current = $this->libraryIds($remote['libraries'] ?? []);
        $libraries ??= $current;
        $roles = array_values(array_filter((array) ($remote['roles'] ?? []), 'is_string'));
        if ($login === true && ! in_array(self::LOGIN_ROLE, $roles, true)) {
            $roles[] = self::LOGIN_ROLE;
        } elseif ($login === false) {
            $roles = array_values(array_diff($roles, [self::LOGIN_ROLE]));
        }
        if ($remote['username'] === $username && $libraries === $current && $roles === array_values(array_filter((array) ($remote['roles'] ?? []), 'is_string'))) {
            return;
        }
        $this->check($client->post('/api/Account/update', [
            'userId' => $remote['id'],
            'username' => $username,
            'roles' => $roles,
            'libraries' => $libraries,
            'ageRestriction' => $remote['ageRestriction'] ?? ['ageRating' => -1, 'includeUnknowns' => true],
            'email' => $remote['email'],
            'identityProvider' => 0,
        ]));
    }

    /** @return list<int> ids of a Kavita library list (LibraryDto[]) */
    private function libraryIds(mixed $libraries): array
    {
        return array_values(array_filter(
            array_map(fn (mixed $library): mixed => is_array($library) ? ($library['id'] ?? null) : null, (array) $libraries),
            'is_int',
        ));
    }

    private function verify(PendingRequest $client, ServiceAccount $account, string $username, ?bool $login): void
    {
        $remote = $this->find($client, $account);
        if ($remote === null || $remote['username'] !== $username
            || ($login !== null && in_array(self::LOGIN_ROLE, (array) ($remote['roles'] ?? []), true) !== $login)) {
            throw new RuntimeException('unavailable');
        }
        $this->assertManaged($remote);
    }

    /** Never let HTTP exceptions (which may carry credentials) escape. */
    private function guard(callable $operation): void
    {
        try {
            $operation();
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }
}
