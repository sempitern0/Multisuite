<?php

namespace Tests\Integration;

use App\Actions\Users\SetVideoRating;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\JellyfinAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparesMediaRoot;
use Tests\TestCase;

/** Startup wizard, private accounts, parental limit and acceleration against the pinned Jellyfin. */
class JellyfinAccountsTest extends TestCase
{
    use PreparesMediaRoot, RefreshDatabase;

    public function test_the_portal_sets_up_jellyfin_and_isolates_each_person(): void
    {
        $url = getenv('JELLYFIN_TEST_URL');
        $root = getenv('MEDIA_TEST_ROOT');
        if (! $url || ! $root) {
            $this->markTestSkipped('Disposable Jellyfin with the media directory mounted required.');
        }
        config(['portal.services.video.enabled' => true, 'provisioning.video.enabled' => true,
            'portal.video_internal_url' => $url, 'provisioning.video.hardware' => 'none']);
        $this->prepareMediaRoot($root);
        $owner = User::factory()->create(['role' => 'owner', 'username' => 'propietaria']);
        $jellyfin = app(JellyfinAccounts::class);
        $this->assertSame('empty', $jellyfin->setupState());

        $jellyfin->connect($owner, 'multisuite-admin', 'disposable-admin-password');
        $this->assertSame('connected', $jellyfin->setupState());
        // Re-authorising an already configured server reuses its administrator.
        $jellyfin->connect($owner, 'multisuite-admin', 'disposable-admin-password');
        $this->assertSame('none', $jellyfin->acceleration());
        $this->assertStringContainsString('Multisuite', (string) $jellyfin->client()->get('/System/Configuration/branding')->json('LoginDisclaimer'));

        $people = [];
        foreach (['ana' => 12, 'bruno' => null] as $username => $rating) {
            $user = User::factory()->create(['role' => 'member', 'username' => $username, 'service_access' => ['video'], 'video_max_rating' => $rating]);
            app(ServiceAccounts::class)->enroll($user, ['video'], $username.'-password-123');
            app(ServiceAccounts::class)->syncUser($user);
            $account = ServiceAccount::where('user_id', $user->id)->where('service', 'video')->sole();
            $this->assertSame('ready', $account->status, $username);
            app(MediaLibrary::class)->sync($user);
            $people[$username] = [$user, $account->fresh()];
        }

        // Each person signs in with the portal password and sees only their library and the common one.
        foreach ($people as $username => [$user, $account]) {
            $login = Http::baseUrl($url)->acceptJson()
                ->withHeaders(['Authorization' => 'MediaBrowser Client="CI", Device="CI", DeviceId="ci-'.$username.'", Version="1"'])
                ->post('/Users/AuthenticateByName', ['Username' => $username, 'Pw' => $username.'-password-123']);
            $this->assertSame(200, $login->status(), $username);
            $this->assertFalse($login->json('User.Policy.IsAdministrator'));
            $this->assertFalse($login->json('User.Policy.EnableAllFolders'));
            $views = Http::baseUrl($url)->acceptJson()
                ->withHeaders(['Authorization' => 'MediaBrowser Client="CI", Device="CI", DeviceId="ci-'.$username.'", Version="1", Token="'.$login->json('AccessToken').'"'])
                ->get('/UserViews', ['userId' => $account->external_id]);
            $this->assertSame(200, $views->status());
            $names = collect($views->json('Items'))->pluck('Name')->all();
            $this->assertContains('Mis vídeos · '.substr($account->remote_id, 0, 8), $names);
            $this->assertContains('Vídeos para todos', $names);
            $other = $username === 'ana' ? $people['bruno'][1] : $people['ana'][1];
            $this->assertNotContains('Mis vídeos · '.substr($other->remote_id, 0, 8), $names, 'Never another person\'s library.');
        }

        [$ana, $anaAccount] = $people['ana'];
        $this->assertSame(12, $this->policy($jellyfin, $anaAccount)['MaxParentalRating']);
        app(SetVideoRating::class)->handle($owner, $ana, 7);
        $this->assertSame(7, $this->policy($jellyfin, $anaAccount)['MaxParentalRating']);

        $jellyfin->lock($anaAccount);
        $this->assertTrue($this->policy($jellyfin, $anaAccount)['IsDisabled']);
        $jellyfin->remove($anaAccount);
        $this->assertSame(404, $jellyfin->client()->get('/Users/'.$anaAccount->external_id)->status());
    }

    /** @return array<string, mixed> */
    private function policy(JellyfinAccounts $jellyfin, ServiceAccount $account): array
    {
        return (array) $jellyfin->client()->get('/Users/'.$account->external_id)->json('Policy');
    }
}
