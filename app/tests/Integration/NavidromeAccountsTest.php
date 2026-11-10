<?php

namespace Tests\Integration;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavidromeAccountsTest extends TestCase
{
    use RefreshDatabase;

    /** @param array<string, string> $credentials */
    private function login(string $url, array $credentials): Response
    {
        // Respect the real server's limiter; do not disable authentication protection.
        $response = Http::post($url.'/auth/login', $credentials);
        for ($attempt = 0; $attempt < 30 && $response->status() === 429; $attempt++) {
            sleep(1);
            $response = Http::post($url.'/auth/login', $credentials);
        }

        return $response;
    }

    public function test_real_navidrome_accepts_created_and_updated_credentials(): void
    {
        $url = getenv('NAVIDROME_TEST_URL');
        if (! $url) {
            $this->markTestSkipped('Disposable Navidrome required.');
        }
        config([
            'portal.services.music.enabled' => true,
            'provisioning.music.enabled' => true,
            'provisioning.music.url' => $url,
            'provisioning.music.username' => 'ci-operator',
            'provisioning.music.password' => 'ci-operator-password',
        ]);
        $response = Http::post($url.'/auth/createAdmin', ['username' => 'ci-operator', 'password' => 'ci-operator-password']);
        $this->assertTrue($response->successful());
        $user = User::factory()->create(['email' => 'suite@example.com']);
        $accounts = app(ServiceAccounts::class);
        $accounts->enroll($user, ['music'], 'first-password');
        $accounts->syncUser($user);
        $this->assertSame('ready', ServiceAccount::firstOrFail()->status);
        $login = $this->login($url, ['username' => $user->email, 'password' => 'first-password']);
        $this->assertTrue($login->successful());
        $this->assertFalse($login->json('isAdmin'));
        $remoteId = ServiceAccount::firstOrFail()->remote_id;
        $user->username = 'suite-user';
        $user->save();
        $accounts->changePassword($user, 'second-password');
        $this->assertSame('ready', ServiceAccount::firstOrFail()->status);
        $this->assertTrue($this->login($url, ['username' => 'suite-user', 'password' => 'second-password'])->successful());
        $this->assertSame($remoteId, ServiceAccount::firstOrFail()->remote_id);
        $this->assertSame('suite-user', ServiceAccount::firstOrFail()->username);
        $this->assertSame(401, $this->login($url, ['username' => $user->email, 'password' => 'second-password'])->status());
        $this->assertSame(401, $this->login($url, ['username' => 'suite-user', 'password' => 'first-password'])->status());
        // Start the second enrollment outside Navidrome's default 20-second window.
        sleep(21);
        $newUser = User::factory()->create(['email' => 'another@example.com']);
        $newUser->username = 'another-user';
        $newUser->save();
        $accounts->enroll($newUser, ['music'], 'another-password');
        $accounts->syncUser($newUser);
        $this->assertTrue($this->login($url, ['username' => 'another-user', 'password' => 'another-password'])->successful());

    }
}
