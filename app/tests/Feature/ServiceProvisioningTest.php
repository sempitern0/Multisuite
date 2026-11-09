<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ServiceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.services.music.enabled' => true,
            'provisioning.music.enabled' => true,
            'provisioning.music.url' => 'http://music.test',
            'provisioning.music.username' => 'operator',
            'provisioning.music.password' => 'admin-secret',
        ]);
        Http::preventStrayRequests();
    }

    public function test_invitation_creates_pending_account_with_encrypted_secret_and_recovers(): void
    {
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $invitation = new Invitation;
        $invitation->email = 'guest@example.com';
        $invitation->role = 'member';
        $invitation->services = ['music'];
        $invitation->invited_by = $owner->id;
        $invitation->token_hash = hash('sha256', str_repeat('a', 64));
        $invitation->expires_at = now()->addDay();
        $invitation->save();
        $url = URL::temporarySignedRoute('invitation.accept', now()->addDay(), ['token' => str_repeat('a', 64)]);
        Http::fake(['*' => Http::response([], 503)]);
        $this->post($url, [
            'name' => 'Guest', 'password' => 'safe-password', 'password_confirmation' => 'safe-password',
        ])->assertRedirect(route('dashboard'));
        $account = ServiceAccount::firstOrFail();
        $this->assertSame('guest@example.com', $account->username);
        $this->assertNotSame('safe-password', DB::table('service_accounts')->value('pending_password'));
        $this->assertStringNotContainsString('safe-password', $account->toJson());
        $this->assertNotNull($account->pending_password);
        $this->fakeRemote($account);
        app(ServiceAccounts::class)->synchronize($account->id);
        $this->assertSame('ready', $account->fresh()?->status);
        $this->assertNull($account->fresh()?->pending_password);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/user')
            && $request['password'] === 'safe-password' && $request['isAdmin'] === false);
    }

    private function fakeRemote(ServiceAccount $account, bool $existing = false): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        $remote = ['id' => $account->remote_id, 'userName' => $account->username, 'isAdmin' => false];
        Http::fake([
            'music.test/auth/login' => Http::response(['token' => 'test-token', 'isAdmin' => true]),
            'music.test/api/user/'.$account->remote_id => $existing
                ? Http::response($remote)
                : Http::sequence()->push([], 404)->push($remote),
            'music.test/api/user' => Http::response($remote, 201),
        ]);
    }

    public function test_reset_password_updates_local_hash_and_managed_remote_account(): void
    {
        $user = User::factory()->create();
        app(ServiceAccounts::class)->enroll($user, ['music'], 'old-password');
        $account = ServiceAccount::firstOrFail();
        $this->fakeRemote($account, true);
        $token = Password::createToken($user);
        $this->post(route('password.update'), [
            'token' => $token, 'email' => $user->email,
            'password' => 'new-password', 'password_confirmation' => 'new-password',
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertNull($account->fresh()?->pending_password);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['password'] === 'new-password');
    }

    public function test_existing_username_conflict_is_not_adopted_and_secret_expires(): void
    {
        $user = User::factory()->create();
        app(ServiceAccounts::class)->enroll($user, ['music'], 'safe-password');
        $account = ServiceAccount::firstOrFail();
        Http::fake([
            'music.test/auth/login' => Http::response(['token' => 'test-token', 'isAdmin' => true]),
            'music.test/api/user/'.$account->remote_id => Http::response([], 404),
            'music.test/api/user' => Http::response([], 422),
        ]);
        app(ServiceAccounts::class)->synchronize($account->id);
        $this->assertSame('conflict', $account->fresh()?->status);
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
        $this->travel(61)->minutes();
        $this->artisan('portal:sync-accounts')->assertSuccessful();
        $this->assertNull($account->fresh()?->pending_password);
        $this->assertSame('expired', $account->fresh()?->status);
    }

    public function test_ambiguous_create_retry_uses_reserved_id_and_newest_password(): void
    {
        $user = User::factory()->create();
        app(ServiceAccounts::class)->enroll($user, ['music'], 'old-password');
        $account = ServiceAccount::firstOrFail();
        $this->fakeRemote($account, true);
        app(ServiceAccounts::class)->changePassword($user, 'new-password');
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/user'));
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['password'] === 'new-password');
        $this->assertSame('ready', $account->fresh()?->status);
    }

    public function test_service_entitlement_cannot_be_forged_at_navigation(): void
    {
        $user = User::factory()->create();
        $user->service_access = [];
        $user->save();
        $this->actingAs($user)->get('/services/music')->assertForbidden();
    }
}
