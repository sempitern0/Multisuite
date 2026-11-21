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
use RuntimeException;
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

    public function test_stale_media_job_cannot_restore_a_revoked_account(): void
    {
        $user = User::factory()->create(['service_access' => ['komga']]);
        $account = app(ServiceAccounts::class)->reserve($user, 'komga');
        $account->status = 'ready';
        $account->save();
        ServiceAccount::whereKey($account->id)->update(['status' => ServiceAccounts::LOCKED]);
        app(KomgaAccounts::class)->ensurePersonalLibrary($account, 'libros', 'Mis libros');
        Http::assertNothingSent();
        $this->assertSame(ServiceAccounts::LOCKED, $account->fresh()->status);
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

    public function test_native_privilege_drift_is_rejected_before_changing_a_password(): void
    {
        $user = User::factory()->create(['role' => 'member', 'service_access' => ['komga']]);
        $connection = new ServiceConnection;
        $connection->service = 'komga';
        $connection->operator_id = 'operator';
        $connection->secret = 'secret-key';
        $connection->save();
        app(ServiceAccounts::class)->enroll($user, ['komga'], 'chosen-password');
        $account = ServiceAccount::where('user_id', $user->id)->where('service', 'komga')->sole();
        $account->external_id = 'promoted-member';
        $account->remote_email = $user->email;
        $account->save();
        Http::fake(['*/api/v2/users' => Http::response([
            ['id' => 'promoted-member', 'email' => $user->email, 'roles' => ['ADMIN', 'USER']],
        ])]);
        app(ServiceAccounts::class)->syncUser($user);
        $this->assertSame('conflict', $account->fresh()->status);
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_setup_uses_the_verified_portal_password_and_never_creates_an_arbitrary_account(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Http::fake(['*/api/v1/claim' => Http::response(['isClaimed' => false])]);
        Livewire::actingAs($owner)->test('pages::admin.komga-connection')
            ->assertSee('Komga está vacío')->assertSee($owner->email)
            ->set('email', 'someone-else@example.com')->set('password', 'not-the-portal-password')
            ->call('connect')->assertHasErrors(['password' => 'current_password'])
            ->assertSet('password', '');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_existing_server_rejects_wrong_credentials_with_an_actionable_message(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Http::fake([
            '*/api/v1/claim' => Http::response(['isClaimed' => true]),
            '*/api/v2/users/me' => Http::response([], 401),
        ]);
        Livewire::actingAs($owner)->test('pages::admin.komga-connection')
            ->assertSee('Komga ya está inicializado')
            ->set('password', 'password')->call('connect')->assertHasErrors('password')
            ->assertSee('Komga ya tiene una cuenta y no acepta estos datos')->assertSet('password', '');
        Http::assertNotSent(fn ($request) => $request->method() !== 'GET');
    }

    public function test_unreachable_server_is_not_reported_as_a_password_error(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Http::fake(['*' => Http::failedConnection()]);
        Livewire::actingAs($owner)->test('pages::admin.komga-connection')
            ->assertSee('Multisuite no puede consultar Komga')
            ->assertDontSee('Contraseña de la cuenta seleccionada');
    }

    public function test_claim_requires_a_boolean_status_before_any_account_is_created(): void
    {
        $owner = User::factory()->create(['role' => 'owner']);
        Http::fake(['*/api/v1/claim' => Http::response(['unexpected' => true])]);
        $this->assertSame('unavailable', app(KomgaAccounts::class)->setupState());
        try {
            app(KomgaAccounts::class)->connect($owner, $owner->email, 'password');
            $this->fail('Malformed claim status must fail closed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('invalid_response', $exception->getMessage());
        }
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
