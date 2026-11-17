<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\KavitaAccounts;
use App\Services\ReadingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReadingSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_portal_can_hand_off_the_linked_listener_once(): void
    {
        config(['portal.reading_autologin' => true, 'portal.services.reading.enabled' => true,
            'portal.services.reading.url' => 'http://localhost:5000', 'provisioning.reading.enabled' => true,
            'provisioning.reading.url' => 'http://kavita:5000']);
        $user = User::factory()->create();
        $user->service_access = ['reading'];
        $user->save();
        $account = new ServiceAccount;
        $account->forceFill(['user_id' => $user->id, 'service' => 'reading', 'status' => 'ready',
            'username' => 'reader', 'remote_id' => (string) Str::uuid()])->save();
        Http::fake(['*/api/Account/login' => Http::response(['email' => KavitaAccounts::identity($account),
            'username' => 'reader', 'roles' => ['Login'], 'token' => 'native-token', 'refreshToken' => 'refresh',
            'authKeys' => ['private-key']])]);
        app(ReadingSession::class)->prepare($user, 'example');
        $launch = $this->actingAs($user)->get('/services/reading')->assertOk()->assertDontSee('native-token');
        $ticket = $launch->viewData('ticket');
        $origin = rtrim((string) config('app.url'), '/');
        $this->post('/api/reading/session', ['ticket' => $ticket], ['Origin' => 'https://other.test'])->assertForbidden();
        $this->post('/api/reading/session', ['ticket' => $ticket], ['Origin' => $origin])
            ->assertOk()->assertSee('native-token')->assertDontSee('private-key');
        $this->post('/api/reading/session', ['ticket' => $ticket], ['Origin' => $origin])->assertForbidden();
    }
}
