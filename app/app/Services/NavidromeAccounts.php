<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ServiceProvisioner;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NavidromeAccounts implements PersonalLibraries, ServiceProvisioner
{
    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $user = User::findOrFail($account->user_id);
        $targetUsername = $user->username ?? $account->username;
        try {
            $client = $this->client();
            $remote = $client->get('/api/user/'.$account->remote_id);
            if ($remote->status() === 404) {
                $created = $client->post('/api/user', [
                    'id' => $account->remote_id,
                    'userName' => $targetUsername,
                    'email' => $user->email,
                    'name' => $user->name,
                    'isAdmin' => false,
                    'password' => $password,
                ]);
                if ($created->status() === 422 || $created->status() === 409) {
                    throw new RuntimeException('conflict');
                }
                if (! $created->successful()) {
                    throw new RuntimeException('unavailable');
                }
                $this->verify($client, $account, $targetUsername);
                $account->username = $targetUsername;

                return;
            }
            if (! $remote->successful()) {
                throw new RuntimeException('unavailable');
            }
            // A previous rename may have succeeded remotely before its response was lost.
            $this->assertIdentity($remote->json(), $account, [$account->username, $targetUsername]);
            // Preserve remote settings, including scrobbleFilter, when updating credentials.
            $updated = $client->put('/api/user/'.$account->remote_id, [
                ...$remote->json(),
                'password' => $password,
                'userName' => $targetUsername,
            ]);
            if (in_array($updated->status(), [409, 422], true)) {
                throw new RuntimeException('conflict');
            }
            if (! $updated->successful()) {
                throw new RuntimeException('unavailable');
            }
            $this->verify($client, $account, $targetUsername);
            $account->username = $targetUsername;
        } catch (ConnectionException) {
            // Never propagate HTTP exceptions containing credential-bearing requests.
            throw new RuntimeException('unavailable');
        }
    }

    public function rename(ServiceAccount $account, string $username): void
    {
        // A random password is never needed here: Navidrome keeps the current one when none is sent.
        $this->update($account, [$account->username, $username], ['userName' => $username], $username);
    }

    public function lock(ServiceAccount $account): void
    {
        // The new password is generated and discarded: nobody, the portal included, can sign in with it.
        $this->update($account, [$account->username], ['password' => bin2hex(random_bytes(32))], $account->username);
    }

    /**
     * Changes an existing remote account, keeping its settings. Accepts any of $knownNames
     * so a retry after a lost response still recognises the account. A missing account is left alone.
     *
     * @param  list<string>  $knownNames
     * @param  array<string, string>  $changes
     */
    private function update(ServiceAccount $account, array $knownNames, #[\SensitiveParameter] array $changes, string $expectedName): void
    {
        try {
            $client = $this->client();
            $remote = $client->get('/api/user/'.$account->remote_id);
            if ($remote->status() === 404) {
                return;
            }
            if (! $remote->successful()) {
                throw new RuntimeException('unavailable');
            }
            $this->assertIdentity($remote->json(), $account, $knownNames);
            $payload = (array) $remote->json();
            unset($payload['password'], $payload['currentPassword']);
            $updated = $client->put('/api/user/'.$account->remote_id, [...$payload, ...$changes]);
            if (in_array($updated->status(), [409, 422], true)) {
                throw new RuntimeException('conflict');
            }
            if (! $updated->successful()) {
                throw new RuntimeException('unavailable');
            }
            $this->verify($client, $account, $expectedName);
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }

    /**
     * Navidrome 0.64 libraries (server/nativeapi/library.go): created with defaultNewUsers off so
     * nobody else receives it, then added to this account's library list. Navidrome scans a new
     * library at once and watches it afterwards (ND_SCANSCHEDULE as a fallback).
     */
    public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void
    {
        $path = PersonalLibraries::MOUNT.'/'.strtolower($account->remote_id).($section === null ? '' : '/'.$section);
        try {
            $client = $this->client();
            $remote = $client->get('/api/user/'.$account->remote_id);
            if ($remote->status() === 404) {
                return;
            }
            if (! $remote->successful()) {
                throw new RuntimeException('unavailable');
            }
            $user = User::findOrFail($account->user_id);
            $this->assertIdentity($remote->json(), $account, [$account->username, $user->username ?? $account->username]);
            $libraries = $client->get('/api/library');
            if (! $libraries->successful()) {
                throw new RuntimeException('unavailable');
            }
            $id = null;
            foreach ((array) $libraries->json() as $library) {
                if (is_array($library) && ($library['path'] ?? null) === $path) {
                    $id = (int) $library['id'];
                }
            }
            if ($id === null) {
                $created = $client->post('/api/library', ['name' => $label.' · '.$remote->json('userName'), 'path' => $path, 'defaultNewUsers' => false]);
                if (! $created->successful() || ! is_numeric($created->json('id'))) {
                    // Typically the personal folder is not mounted in the Navidrome container.
                    throw new RuntimeException($created->clientError() ? 'configuration' : 'unavailable');
                }
                $id = (int) $created->json('id');
            }
            $assigned = $client->get('/api/user/'.$account->remote_id.'/library');
            if (! $assigned->successful()) {
                throw new RuntimeException('unavailable');
            }
            $ids = array_values(array_map(fn (mixed $library): int => (int) (is_array($library) ? ($library['id'] ?? 0) : 0), (array) $assigned->json()));
            if (! in_array($id, $ids, true)) {
                $updated = $client->put('/api/user/'.$account->remote_id.'/library', ['libraryIds' => [...$ids, $id]]);
                if (! $updated->successful()) {
                    throw new RuntimeException('unavailable');
                }
            }
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }

    public function remove(ServiceAccount $account): void
    {
        try {
            $client = $this->client();
            $remote = $client->get('/api/user/'.$account->remote_id);
            if ($remote->status() === 404) {
                return;
            }
            if (! $remote->successful()) {
                throw new RuntimeException('unavailable');
            }
            $user = User::findOrFail($account->user_id);
            $this->assertIdentity($remote->json(), $account, [$account->username, $user->username ?? $account->username]);
            $deleted = $client->delete('/api/user/'.$account->remote_id);
            if (! $deleted->successful() && $deleted->status() !== 404) {
                throw new RuntimeException('unavailable');
            }
            if ($client->get('/api/user/'.$account->remote_id)->status() !== 404) {
                throw new RuntimeException('unavailable');
            }
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }
    }

    public function verifyForLogin(ServiceAccount $account): void
    {
        // Navidrome's native user read checks the authenticated listener's own UUID.
        // The gateway accepts this assertion only over its private Unix socket.
        $base = rtrim((string) parse_url((string) config('provisioning.music.url'), PHP_URL_PATH), '/');
        $client = Http::baseUrl('http://localhost'.$base)->acceptJson()->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_UNIX_SOCKET_PATH => '/run/music-identity/lookup.sock'], 'proxy' => ''])
            ->withHeaders(['X-Music-Listener' => $account->username])->connectTimeout(2)->timeout(6);
        try {
            $response = $client->get('/api/user/'.$account->remote_id);
        } catch (ConnectionException) {
            abort(503, 'music_unreachable');
        }
        if (in_array($response->status(), [401, 403, 404], true)) {
            abort(409, 'music_identity');
        }
        abort_unless($response->successful(), 503, 'music_unreachable');
        try {
            $this->assertIdentity($response->json(), $account, [$account->username]);
        } catch (RuntimeException) {
            abort(409, 'music_identity');
        }
    }

    /**
     * Albums and songs matching $query in the libraries Navidrome gives this account (MCP-06).
     * The query is restricted to those libraries and every result is filtered again by library,
     * so nothing from another person's library can come back.
     *
     * @return array{albums: list<array{title: string, artist: string|null, year: int|null}>, songs: list<array{title: string, artist: string|null, album: string|null}>}
     */
    public function searchFor(ServiceAccount $account, string $query): array
    {
        try {
            $client = $this->client();
            $this->verify($client, $account, $account->username);
            $libraries = $client->get('/api/user/'.$account->remote_id.'/library');
            if (! $libraries->successful() || ! is_array($libraries->json())) {
                throw new RuntimeException('unavailable');
            }
            $allowed = array_values(array_filter(array_map(fn ($library) => is_array($library) && isset($library['id']) ? (int) $library['id'] : null, $libraries->json())));
            if ($allowed === []) {
                return ['albums' => [], 'songs' => []];
            }
            $filter = fn ($item): bool => is_array($item) && in_array((int) ($item['libraryId'] ?? 0), $allowed, true);
            // Repeated library_id=… parameters (Navidrome reads them as a list), not PHP's library_id[0]=….
            $libraryQuery = implode('&', array_map(fn (int $id): string => 'library_id='.$id, $allowed));
            $albums = $client->get('/api/album?name='.rawurlencode($query).'&'.$libraryQuery.'&_start=0&_end=20');
            $songs = $client->get('/api/song?title='.rawurlencode($query).'&'.$libraryQuery.'&_start=0&_end=20');
            if (! $albums->successful() || ! $songs->successful()) {
                throw new RuntimeException('unavailable');
            }
        } catch (ConnectionException) {
            throw new RuntimeException('unavailable');
        }

        return [
            'albums' => array_values(array_map(fn (array $item): array => [
                'title' => (string) ($item['name'] ?? ''),
                'artist' => isset($item['albumArtist']) ? (string) $item['albumArtist'] : null,
                'year' => isset($item['maxYear']) && (int) $item['maxYear'] > 0 ? (int) $item['maxYear'] : null,
            ], array_filter((array) $albums->json(), $filter))),
            'songs' => array_values(array_map(fn (array $item): array => [
                'title' => (string) ($item['title'] ?? ''),
                'artist' => isset($item['artist']) ? (string) $item['artist'] : null,
                'album' => isset($item['album']) ? (string) $item['album'] : null,
            ], array_filter((array) $songs->json(), $filter))),
        ];
    }

    private function client(): PendingRequest
    {
        $url = ServiceUrl::validated((string) config('provisioning.music.url'));

        $client = Http::baseUrl($url)->acceptJson()->asJson()->withoutRedirecting()->connectTimeout(3)->timeout(8);
        $login = $client->post('/auth/login', [
            'username' => config('provisioning.music.username'),
            'password' => config('provisioning.music.password'),
        ]);
        if ($login->serverError() || $login->status() === 429) {
            throw new RuntimeException('unavailable');
        }
        $token = $login->json('token');
        if (! $login->successful() || ! is_string($token) || $token === '' || $login->json('isAdmin') !== true) {
            throw new RuntimeException('configuration');
        }

        return $client->withHeaders(['X-ND-Authorization' => 'Bearer '.$token]);
    }

    private function verify(PendingRequest $client, ServiceAccount $account, string $username): void
    {
        $response = $client->get('/api/user/'.$account->remote_id);
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }
        $this->assertIdentity($response->json(), $account, [$username]);
    }

    /** @param list<string> $usernames */
    private function assertIdentity(mixed $remote, ServiceAccount $account, array $usernames): void
    {
        if (! is_array($remote) || ($remote['id'] ?? null) !== $account->remote_id
            || ! in_array($remote['userName'] ?? null, $usernames, true) || ($remote['isAdmin'] ?? null) !== false) {
            throw new RuntimeException('conflict');
        }
    }
}
