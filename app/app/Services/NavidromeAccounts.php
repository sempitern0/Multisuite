<?php

namespace App\Services;

use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NavidromeAccounts
{
    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
        $user = User::findOrFail($account->user_id);
        $targetUsername = $user->username ?? $account->username;
        $url = rtrim((string) config('provisioning.music.url'), '/');
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['scheme'] ?? '', ['https', 'http'], true)
            || (app()->isProduction() && $parts['scheme'] !== 'https')) {
            throw new RuntimeException('configuration');
        }

        try {
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
            $client = $client->withHeaders(['X-ND-Authorization' => 'Bearer '.$token]);
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
