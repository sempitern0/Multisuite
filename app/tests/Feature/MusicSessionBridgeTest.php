<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\MusicSessionBridge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MusicSessionBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.music_session_bridge' => true,
            'portal.services.music.enabled' => true,
            'portal.services.music.url' => 'http://localhost:4533/music/app/#/album',
            'provisioning.music.enabled' => true,
        ]);
    }

    /** @return array{User, ServiceAccount} */
    private function account(): array
    {
        $user = User::factory()->create();
        $user->service_access = ['music'];
        $user->save();
        $account = new ServiceAccount;
        $account->user_id = $user->id;
        $account->service = 'music';
        $account->remote_id = (string) Str::uuid();
        $account->username = 'music-'.$user->id;
        $account->status = 'ready';
        $account->save();

        return [$user, $account];
    }

    private function ticket(ServiceAccount $account): string
    {
        return app(MusicSessionBridge::class)->issue($account, config('portal.services.music.url'));
    }

    public function test_launcher_issues_encrypted_pass_for_authenticated_owner_only(): void
    {
        [$user, $account] = $this->account();
        [, $other] = $this->account();
        $response = $this->actingAs($user)->get('/services/music?user_id='.$other->user_id);
        $response->assertRedirect()->assertHeader('Referrer-Policy', 'no-referrer');
        $target = $response->headers->get('Location');
        $this->assertStringStartsWith('http://localhost:4533/_multisuite/session#ticket=', $target);
        $this->assertStringNotContainsString($account->remote_id, $target);
        $this->assertStringNotContainsString($user->email, $target);
        parse_str(parse_url($target, PHP_URL_FRAGMENT), $fragment);
        $this->postJson('/api/music/session/verify', ['ticket' => $fragment['ticket']])
            ->assertOk()->assertExactJson([
                'id' => $account->remote_id,
                'username' => $account->username,
                'destination' => config('portal.services.music.url'),
            ]);
    }

    public function test_verification_is_stateless_and_never_returns_authentication_credentials(): void
    {
        [, $account] = $this->account();
        $this->postJson('/api/music/session/verify', ['ticket' => $this->ticket($account)])
            ->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('password')
            ->assertCookieMissing(config('session.cookie'));
    }

    public function test_tampering_expiry_and_destination_change_are_rejected(): void
    {
        [, $account] = $this->account();
        $ticket = $this->ticket($account);
        $this->postJson('/api/music/session/verify', ['ticket' => 'forged'])->assertForbidden();
        $this->travel(61)->seconds();
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
        $this->travelBack();
        config(['portal.services.music.url' => 'https://other.example']);
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
    }

    public function test_revoked_entitlement_pending_account_and_initial_password_block_existing_pass(): void
    {
        [$user, $account] = $this->account();
        $ticket = $this->ticket($account);
        $user->service_access = [];
        $user->save();
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
        $user->service_access = ['music'];
        $user->must_change_password = true;
        $user->save();
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
        $user->must_change_password = false;
        $user->save();
        $account->status = 'pending';
        $account->save();
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
        $this->actingAs($user)->get('/services/music')->assertRedirect(route('dashboard'));
    }

    public function test_disabled_bridge_preserves_direct_links_and_rejects_verification(): void
    {
        [$user, $account] = $this->account();
        $ticket = $this->ticket($account);
        config(['portal.music_session_bridge' => false]);
        $this->actingAs($user)->get('/services/music')->assertRedirect(config('portal.services.music.url'));
        $this->postJson('/api/music/session/verify', ['ticket' => $ticket])->assertForbidden();
    }
}
