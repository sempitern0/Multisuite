<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A service added to the portal reaches existing people at their next sign-in with a password,
 * except where an administrator left it out (account creation, invitation) or turned it off.
 */
class NewServicesOnSignInTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['music', 'reading'] as $service) {
            config([
                'portal.services.'.$service.'.enabled' => true,
                'provisioning.'.$service.'.enabled' => true,
                'provisioning.'.$service.'.url' => 'http://'.$service.'.test',
                'provisioning.'.$service.'.username' => 'operator',
                'provisioning.'.$service.'.password' => 'secret',
            ]);
        }
        // The services are unreachable here: accounts stay pending, which is enough to see them created.
        Http::fake(fn () => Http::response([], 503));
    }

    private function member(array $access, ?array $revoked = null): User
    {
        $user = User::factory()->create(['email' => 'persona@example.com']);
        $user->password = 'right-password';
        $user->service_access = $access;
        $user->services_revoked = $revoked;
        $user->save();

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->withoutDefer()->post(route('login.store'), ['email' => $user->email, 'password' => 'right-password'])->assertRedirect();
    }

    public function test_an_existing_person_gets_a_service_added_after_their_account_at_next_sign_in(): void
    {
        $user = $this->member(['music']);

        $this->signIn($user);

        $this->assertSame(['music', 'reading'], $user->fresh()->service_access);
        $account = ServiceAccount::where('service', 'reading')->firstOrFail();
        $this->assertSame($user->id, $account->user_id);
        $this->assertNotSame(ServiceAccounts::AWAITING_PASSWORD, $account->status, 'The password from this sign-in is used at once.');
        $event = AdminAuditEvent::where('action', 'service.access_granted')->firstOrFail();
        $this->assertNull($event->actor_id);
        $this->assertSame('reading', $event->service_id);
    }

    public function test_a_service_an_administrator_turned_off_is_not_granted_back(): void
    {
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $user = $this->member(['music', 'reading']);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        // No Lectura account exists yet, so turning it off needs no call to the service.
        Livewire::test('pages::admin.user-edit', ['user' => $user])->call('setService', 'reading', false)->assertHasNoErrors();
        $this->assertSame(['reading'], $user->fresh()->services_revoked);
        auth()->logout();

        $this->signIn($user);

        $this->assertSame(['music'], $user->fresh()->service_access);
    }

    public function test_services_left_out_at_creation_stay_out(): void
    {
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        Livewire::test('pages::admin.user-create')
            ->set('name', 'Sin lectura')->set('email', 'sinlectura@example.com')->set('role', 'member')
            ->set('password', 'temporary-password-1')->set('password_confirmation', 'temporary-password-1')
            ->set('services', ['music'])->call('create')->assertHasNoErrors();

        $created = User::where('email', 'sinlectura@example.com')->firstOrFail();
        $this->assertSame(['reading'], $created->services_revoked);
        app(ServiceAccounts::class)->changePassword($created, 'first-own-password');
        $this->assertSame(['music'], $created->fresh()->service_access);
        $this->assertSame(['music'], ServiceAccount::where('user_id', $created->id)->pluck('service')->all());
    }

    public function test_granting_again_clears_the_mark_and_a_suspended_person_gets_nothing(): void
    {
        $user = $this->member(['music'], ['reading']);
        $user->suspended_at = now();
        $user->save();
        $this->assertFalse(app(ServiceAccounts::class)->grantNewServices($user));

        $user->suspended_at = null;
        $user->save();
        $this->assertFalse(app(ServiceAccounts::class)->grantNewServices($user));
        $user->services_revoked = [];
        $this->assertTrue(app(ServiceAccounts::class)->grantNewServices($user));
        $this->assertSame(['music', 'reading'], $user->fresh()->service_access);
    }
}
