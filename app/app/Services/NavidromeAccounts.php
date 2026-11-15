<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Provisioning\ServiceProvisioner;
use App\Services\Provisioning\ServiceUrl;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NavidromeAccounts implements ServiceProvisioner
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
