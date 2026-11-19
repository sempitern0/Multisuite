<?php

namespace Tests\Integration;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\Files\MediaLibrary;
use App\Services\KomgaAccounts;
use App\Services\KomgaSession;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\PreparesMediaRoot;
use Tests\TestCase;

/** Account creation, isolation and session cookies against the pinned native server. */
class KomgaAccountsTest extends TestCase
{
    use PreparesMediaRoot, RefreshDatabase;

    public function test_existing_and_new_users_get_private_libraries_and_native_sessions(): void
    {
        $url = getenv('KOMGA_TEST_URL');
        $root = getenv('MEDIA_TEST_ROOT');
        if (! $url || ! $root) {
            $this->markTestSkipped('Disposable Komga with the media directory mounted required.');
        }
        config(['portal.services.komga.enabled' => true, 'provisioning.komga.enabled' => true,
            'portal.komga_internal_url' => $url, 'portal.services.komga.url' => $url]);
        $this->prepareMediaRoot($root);
        $existing = User::factory()->create(['email' => 'existing@example.com', 'password' => 'existing-password']);
        $owner = User::factory()->create(['role' => 'owner', 'email' => 'operator@example.com']);
        $komga = app(KomgaAccounts::class);
        $komga->connect($owner, $owner->email, 'operator-password');
        $accounts = app(ServiceAccounts::class);
        $legacy = ServiceAccount::where('user_id', $existing->id)->where('service', 'komga')->firstOrFail();
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, $legacy->status);
        app(KomgaSession::class)->prepare($existing, 'existing-password');
        $this->assertNotNull(session('komga.login'));
        $this->assertSame('ready', $legacy->fresh()->status);
        $new = User::factory()->create(['email' => 'invited@example.com', 'service_access' => ['komga']]);
        $accounts->enroll($new, ['komga'], 'invited-password');
        $accounts->syncUser($new);
        $account = ServiceAccount::where('user_id', $new->id)->where('service', 'komga')->firstOrFail();
        $this->assertSame('ready', $account->status);
        $this->assertNull($account->pending_password);
        app(KomgaSession::class)->prepare($new, 'invited-password');
        $this->assertNotNull(session('komga.login'));
        $native = Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v2/users/me');
        $this->assertTrue($native->successful(), $native->body());
        $this->assertNotContains('ADMIN', $native->json('roles'));
        $this->assertFalse($native->json('sharedAllLibraries'));
        $libraries = Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v1/libraries')->json();
        $this->assertCount(2, $libraries);
        $this->assertEqualsCanonicalizing(array_values($account->library_ids), array_column($libraries, 'id'));
        $this->assertEmpty(array_intersect(array_values($legacy->fresh()->library_ids), array_column($libraries, 'id')));
        $this->storeConnected($new, 'books', 'manual.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        $this->assertGreaterThan(0, app(MediaLibrary::class)->sync($new));
        $count = count($komga->client()->get('/api/v1/libraries')->json());
        app(MediaLibrary::class)->sync($new);
        $this->assertCount($count, $komga->client()->get('/api/v1/libraries')->json());
        $accounts->changePassword($new, 'replacement-password');
        $this->assertTrue(Http::withBasicAuth($new->email, 'replacement-password')->get($url.'/api/v2/users/me')->successful());
        $this->assertSame(401, Http::withBasicAuth($new->email, 'invited-password')->get($url.'/api/v2/users/me')->status());
        $komga->lock($account->fresh());
        $this->assertSame(401, Http::withBasicAuth($new->email, 'replacement-password')->get($url.'/api/v2/users/me')->status());
        $komga->remove($account->fresh());
        $komga->remove($account->fresh());
        $this->assertTrue(Http::withBasicAuth($existing->email, 'existing-password')->get($url.'/api/v2/users/me')->successful());
    }
}
