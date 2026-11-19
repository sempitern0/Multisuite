<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\ServiceConnection;
use App\Models\User;
use App\Services\Files\ConnectedFolders;
use App\Services\KomgaAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class KomgaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['portal.services.komga.enabled' => true, 'provisioning.komga.enabled' => true,
            'portal.services.reading.enabled' => false, 'portal.komga_internal_url' => 'http://komga.test']);
        Http::preventStrayRequests();
    }

    public function test_existing_accounts_are_reserved_idempotently_preserving_legacy_media_and_revocation(): void
    {
        $user = User::factory()->create();
        $legacy = app(ServiceAccounts::class)->reserve($user, 'reading');
        $revoked = User::factory()->create(['service_access' => [], 'services_revoked' => ['reading']]);
        $connection = new ServiceConnection;
        $connection->service = 'komga';
        $connection->operator_id = 'operator';
        $connection->secret = 'secret-key';
        $connection->save();
        app(KomgaAccounts::class)->reserveExisting();
        app(KomgaAccounts::class)->reserveExisting();
        $account = ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->sole();
        $this->assertSame($legacy->remote_id, $account->media_id);
        $this->assertNotSame($legacy->remote_id, $account->remote_id);
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, $account->status);
        $this->assertNull($account->pending_password);
        $this->assertDatabaseMissing('service_accounts', ['user_id' => $revoked->id, 'service' => 'komga']);
        $this->assertSame('komga', ConnectedFolders::definition('books')['service']);
        $this->assertSame(['komga'], app(ServiceAccounts::class)->currentServices(['reading']));
        $this->assertNotSame('secret-key', $connection->getRawOriginal('secret'));
        Http::assertNothingSent();
    }

    public function test_email_collision_does_not_reset_or_take_over_an_existing_account(): void
    {
        $connection = new ServiceConnection;
        $connection->service = 'komga';
        $connection->operator_id = 'operator';
        $connection->secret = 'secret-key';
        $connection->save();
        $user = User::factory()->create(['service_access' => ['komga']]);
        Http::fake([
            '*/api/v2/users' => Http::response([['id' => 'foreign-id', 'email' => $user->email, 'roles' => ['USER']]]),
            '*/api/v2/users/me' => Http::response([], 401),
        ]);
        app(ServiceAccounts::class)->enroll($user, ['komga'], 'chosen-password');
        app(ServiceAccounts::class)->syncUser($user);
        $account = ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->sole();
        $this->assertSame('conflict', $account->status);
        $this->assertNull($account->external_id);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_members_cannot_authorise_the_administrative_connection(): void
    {
        $user = User::factory()->create(['role' => 'member']);
        $this->actingAs($user)->get(route('komga.connect'))->assertForbidden();
        Livewire::actingAs($user)->test('pages::admin.komga-connection')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_current_password_is_required_to_renew_reading_access(): void
    {
        $user = User::factory()->create(['service_access' => ['komga']]);
        Livewire::actingAs($user)->test('pages::komga-confirm')
            ->set('password', 'wrong-password')->call('open')->assertHasErrors('password');
        Http::assertNothingSent();
    }
}
