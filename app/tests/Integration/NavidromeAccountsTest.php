<?php

namespace Tests\Integration;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\NavidromeAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparesMediaRoot;
use Tests\TestCase;

class NavidromeAccountsTest extends TestCase
{
    use PreparesMediaRoot, RefreshDatabase;

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
        // An administrator's rename keeps the password; a lock keeps the account but stops sign-in.
        // Navidrome's login limiter is a sliding window: wait two windows so the operator's own logins fit.
        sleep(41);
        $account = ServiceAccount::where('user_id', $user->id)->firstOrFail();
        app(NavidromeAccounts::class)->rename($account, 'renamed-user');
        $this->assertTrue($this->login($url, ['username' => 'renamed-user', 'password' => 'second-password'])->successful());
        $account->username = 'renamed-user';
        $account->save();
        app(NavidromeAccounts::class)->lock($account);
        $this->assertSame(401, $this->login($url, ['username' => 'renamed-user', 'password' => 'second-password'])->status());
        $this->assertSame($remoteId, $account->remote_id);
        // Start the second enrollment outside Navidrome's sliding 20-second window.
        sleep(41);
        $newUser = User::factory()->create(['email' => 'another@example.com']);
        $newUser->username = 'another-user';
        $newUser->save();
        $accounts->enroll($newUser, ['music'], 'another-password');
        $accounts->syncUser($newUser);
        $this->assertTrue($this->login($url, ['username' => 'another-user', 'password' => 'another-password'])->successful());
        $account = ServiceAccount::where('user_id', $newUser->id)->firstOrFail();
        app(NavidromeAccounts::class)->remove($account);
        $this->assertSame(401, $this->login($url, ['username' => 'another-user', 'password' => 'another-password'])->status());
        // Retry in a fresh rate-limit window, as an operator would after a failed response.
        sleep(21);
        app(NavidromeAccounts::class)->remove($account);
    }

    public function test_real_navidrome_shows_a_connected_folder_only_to_its_owner(): void
    {
        $url = getenv('NAVIDROME_TEST_URL');
        $root = getenv('MEDIA_TEST_ROOT');
        if (! $url || ! $root) {
            $this->markTestSkipped('Disposable Navidrome with the media folder mounted required.');
        }
        config([
            'portal.services.music.enabled' => true,
            'provisioning.music.enabled' => true,
            'provisioning.music.url' => $url,
            'provisioning.music.username' => 'ci-operator',
            'provisioning.music.password' => 'ci-operator-password',
        ]);
        // The account test may have created the operator already; creating it again is refused harmlessly.
        Http::post($url.'/auth/createAdmin', ['username' => 'ci-operator', 'password' => 'ci-operator-password']);
        $this->prepareMediaRoot($root);
        sleep(21);
        $people = [];
        foreach (['ana', 'bea'] as $name) {
            $user = User::factory()->create(['email' => $name.'@example.com']);
            $user->username = $name.'-music';
            $user->service_access = ['music'];
            $user->save();
            app(ServiceAccounts::class)->enroll($user, ['music'], $name.'-password');
            app(ServiceAccounts::class)->syncUser($user);
            $this->assertSame('ready', ServiceAccount::where('user_id', $user->id)->firstOrFail()->status);
            $people[$name] = $user;
        }
        $song = "ID3\x03\x00\x00\x00\x00\x00\x0a".str_repeat("\x00", 10)."\xff\xfb\x90\x64".str_repeat("\x00", 24);
        $this->storeConnected($people['ana'], 'music', 'cancion.mp3', $song);
        sleep(21);

        app(MediaLibrary::class)->sync($people['ana']);
        app(MediaLibrary::class)->sync($people['bea']);

        $ana = ServiceAccount::where('user_id', $people['ana']->id)->firstOrFail();
        $bea = ServiceAccount::where('user_id', $people['bea']->id)->firstOrFail();
        $this->assertFileExists($root.'/.multisuite-media/music/'.strtolower($ana->remote_id).'/cancion.mp3');
        $client = app(NavidromeAccounts::class);
        $libraries = (fn () => $this->client())->call($client);
        $path = '/personal/'.strtolower($ana->remote_id);
        $library = collect((array) $libraries->get('/api/library')->json())->first(fn ($library) => ($library['path'] ?? null) === $path);
        $this->assertNotNull($library, 'Navidrome created the personal library from the mounted folder.');
        $this->assertContains($library['id'], array_column((array) $libraries->get('/api/user/'.$ana->remote_id.'/library')->json(), 'id'));
        $this->assertNotContains($library['id'], array_column((array) $libraries->get('/api/user/'.$bea->remote_id.'/library')->json(), 'id'));
    }
}
