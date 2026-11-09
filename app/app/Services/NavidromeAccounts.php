<?php

namespace App\Services;

use App\Models\ServiceAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class NavidromeAccounts
{
    public function synchronize(ServiceAccount $account, #[\SensitiveParameter] string $password): void
    {
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
            $token = $login->json('token');
            if (! $login->successful() || ! is_string($token) || $token === '' || $login->json('isAdmin') !== true) {
                throw new RuntimeException('configuration');
            }
            $client = $client->withHeaders(['X-ND-Authorization' => 'Bearer '.$token]);
            $remote = $client->get('/api/user/'.$account->remote_id);
            if ($remote->status() === 404) {
                $created = $client->post('/api/user', [
                    'id' => $account->remote_id,
                    'userName' => $account->username,
                    'email' => $account->username,
                    'name' => $account->username,
                    'isAdmin' => false,
                    'password' => $password,
                ]);
                if ($created->status() === 422 || $created->status() === 409) {
                    throw new RuntimeException('conflict');
                }
                if (! $created->successful()) {
                    throw new RuntimeException('unavailable');
                }
                $this->verify($client, $account);

                return;
            }
            if (! $remote->successful()) {
                throw new RuntimeException('unavailable');
            }
            $this->assertIdentity($remote->json(), $account);
            // Preserve remote settings, including scrobbleFilter, when updating credentials.
            $updated = $client->put('/api/user/'.$account->remote_id, [
                ...$remote->json(),
                'password' => $password,
            ]);
            if (! $updated->successful()) {
                throw new RuntimeException('unavailable');
            }
            $this->verify($client, $account);
        } catch (ConnectionException) {
            // Never propagate HTTP exceptions containing credential-bearing requests.
            throw new RuntimeException('unavailable');
        }
    }

    private function verify(PendingRequest $client, ServiceAccount $account): void
    {
        $response = $client->get('/api/user/'.$account->remote_id);
        if (! $response->successful()) {
            throw new RuntimeException('unavailable');
        }
        $this->assertIdentity($response->json(), $account);
    }

    private function assertIdentity(mixed $remote, ServiceAccount $account): void
    {
        if (! is_array($remote) || ($remote['id'] ?? null) !== $account->remote_id
            || ($remote['userName'] ?? null) !== $account->username || ($remote['isAdmin'] ?? null) !== false) {
            throw new RuntimeException('conflict');
        }
    }
}
