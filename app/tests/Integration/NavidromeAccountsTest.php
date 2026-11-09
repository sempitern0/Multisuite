<?php

namespace Tests\Integration;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NavidromeAccountsTest extends TestCase
{
    use RefreshDatabase;

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
        $login = Http::post($url.'/auth/login', ['username' => $user->email, 'password' => 'first-password']);
        $this->assertTrue($login->successful());
        $this->assertFalse($login->json('isAdmin'));
        $accounts->changePassword($user, 'second-password');
        $this->assertSame('ready', ServiceAccount::firstOrFail()->status);
        $this->assertTrue(Http::post($url.'/auth/login', ['username' => $user->email, 'password' => 'second-password'])->successful());
        $this->assertSame(401, Http::post($url.'/auth/login', ['username' => $user->email, 'password' => 'first-password'])->status());
    }
}
