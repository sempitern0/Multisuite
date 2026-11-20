<?php

namespace Tests\Feature;

use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\KomgaSession;
use App\Services\ServiceAccounts;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KomgaSessionTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'bmF0aXZlLWtvbWdhLXNlc3Npb24=';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.services.komga.enabled' => true, 'provisioning.komga.enabled' => true,
            'portal.services.komga.url' => 'http://localhost:25600',
            'portal.komga_internal_url' => 'http://komga-gateway:8080']);
        Http::preventStrayRequests();
        $connection = new ServiceConnection;
        $connection->service = 'komga';
        $connection->secret = 'test-api-key';
        $connection->operator_id = 'native-id';
        $connection->save();
    }

    private function operator(): User
    {
        $user = User::factory()->create(['role' => 'owner']);
        $account = app(ServiceAccounts::class)->reserve($user, 'komga');
        $account->external_id = 'native-id';
        $account->remote_email = $user->email;
        $account->status = 'ready';
        $account->save();

        return $user;
    }

    private function native(User $user): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/api/v2/users/me' => Http::response([
            'id' => 'native-id', 'email' => $user->email, 'roles' => ['ADMIN', 'USER'],
        ], 200, ['Set-Cookie' => 'KOMGA-SESSION='.self::COOKIE.'; Path=/; HttpOnly'])]);
    }

    private function launch(User $user): string
    {
        return $this->actingAs($user)->withSession([Auth::guard('web')->getName() => $user->id])
            ->get('/services/komga')->assertOk()->assertDontSee(self::COOKIE)->viewData('ticket');
    }

    public function test_verified_session_is_handed_off_once_without_exposing_passwords(): void
    {
        $user = $this->operator();
        $this->native($user);
        app(KomgaSession::class)->prepare($user, 'native-password');
        $ticket = $this->launch($user);
        $origin = rtrim((string) config('app.url'), '/');
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => 'https://foreign.test'])->assertForbidden();
        $response = $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => $origin])
            ->assertStatus(303)->assertHeader('Location', '/')->assertDontSee('native-password');
        $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
        $this->assertSame(self::COOKIE, $cookies['KOMGA-SESSION']->getValue());
        $this->assertTrue($cookies['KOMGA-SESSION']->isHttpOnly());
        $this->assertStringContainsString('KOMGA-SESSION='.self::COOKIE.';', (string) $cookies['KOMGA-SESSION']);
        $this->assertLessThan(time(), $cookies['komga-remember-me']->getExpiresTime());
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => $origin])->assertForbidden();
    }

    public function test_wrong_native_identity_cannot_be_reused(): void
    {
        $user = $this->operator();
        $other = User::factory()->create(['role' => 'owner']);
        $this->native($other);
        app(KomgaSession::class)->prepare($user, 'password');
        $this->assertNull(session('komga.login'));
        $this->actingAs($user)->get('/services/komga')->assertRedirect(route('komga.confirm'));
    }

    public function test_role_revocation_and_portal_logout_invalidate_unused_tickets(): void
    {
        $user = $this->operator();
        $this->native($user);
        app(KomgaSession::class)->prepare($user, 'password');
        $ticket = $this->launch($user);
        $user->forceFill(['service_access' => []])->save();
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])->assertForbidden();
        $user->forceFill(['service_access' => ['komga']])->save();
        $ticket = $this->launch($user);
        session()->invalidate();
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])->assertForbidden();
    }

    public function test_expired_tickets_and_changed_native_identity_fail_closed(): void
    {
        $user = $this->operator();
        $this->native($user);
        app(KomgaSession::class)->prepare($user, 'password');
        $ticket = $this->launch($user);
        $this->travel(61)->seconds();
        $origin = rtrim((string) config('app.url'), '/');
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => $origin])->assertForbidden();
        $this->travelBack();
        $ticket = $this->launch($user);
        $this->native(User::factory()->create(['role' => 'owner']));
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => $origin])
            ->assertStatus(303)->assertHeader('Location', rtrim((string) config('app.url'), '/').'/services/komga/confirm');
    }

    public function test_outage_does_not_break_portal_login_or_keep_a_stale_session(): void
    {
        $user = $this->operator();
        session()->put('komga.login', 'old-session');
        Http::fake(['*' => Http::failedConnection()]);
        app(KomgaSession::class)->prepare($user, 'password');
        $this->assertNull(session('komga.login'));
    }

    public function test_password_change_invalidates_the_prepared_session(): void
    {
        $user = $this->operator();
        $this->native($user);
        app(KomgaSession::class)->prepare($user, 'password');
        $ticket = $this->launch($user);
        $user->update(['password' => 'new-password']);
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])
            ->assertStatus(303)->assertHeader('Location', rtrim((string) config('app.url'), '/').'/services/komga/confirm');
    }

    public function test_service_links_share_tile_permissions_and_use_full_navigation(): void
    {
        $user = $this->operator();
        $this->actingAs($user);
        $links = collect(Navigation::for($user)['groups'])->flatMap(fn ($group) => $group['items']);
        $komga = $links->firstWhere('href', route('services.open', 'komga'));
        $this->assertNotNull($komga);
        $this->assertFalse($komga['spa']);
        $this->assertSame('Lectura', $komga['label']);
        $user->forceFill(['service_access' => []])->save();
        $links = collect(Navigation::for($user)['groups'])->flatMap(fn ($group) => $group['items']);
        $this->assertNull($links->firstWhere('href', route('services.open', 'komga')));
        $this->get('/services/komga')->assertForbidden();
    }
}
