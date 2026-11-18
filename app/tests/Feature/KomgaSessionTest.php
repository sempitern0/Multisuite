<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\KomgaSession;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KomgaSessionTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'nativeKomgaSession1234567890';

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.services.komga.enabled' => true,
            'portal.services.komga.url' => 'http://localhost:25600',
            'portal.komga_internal_url' => 'http://komga-gateway:8080']);
        Http::preventStrayRequests();
    }

    private function operator(): User
    {
        return User::factory()->create(['role' => 'owner']);
    }

    private function native(User $user): void
    {
        Http::fake(['*/api/v1/users/me' => Http::response([
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
        $ticket = $this->launch($user);
        $response = $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])
            ->assertStatus(303)->assertHeader('Location', '/login?multisuite=manual');
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertLessThan(time(), $cookie->getExpiresTime());
        }
    }

    public function test_role_revocation_and_portal_logout_invalidate_unused_tickets(): void
    {
        $user = $this->operator();
        $this->native($user);
        app(KomgaSession::class)->prepare($user, 'password');
        $ticket = $this->launch($user);
        $user->update(['role' => 'member']);
        $this->post('/api/komga/session', ['ticket' => $ticket], ['Origin' => rtrim((string) config('app.url'), '/')])->assertForbidden();
        $user->update(['role' => 'owner']);
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
            ->assertStatus(303)->assertHeader('Location', '/login?multisuite=manual');
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
            ->assertStatus(303)->assertHeader('Location', '/login?multisuite=manual');
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
        $user->update(['role' => 'member']);
        $links = collect(Navigation::for($user)['groups'])->flatMap(fn ($group) => $group['items']);
        $this->assertNull($links->firstWhere('href', route('services.open', 'komga')));
        $this->get('/services/komga')->assertForbidden();
    }
}
