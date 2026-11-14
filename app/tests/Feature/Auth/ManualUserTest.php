<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\ServiceAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesAdminPages;
use Tests\TestCase;

class ManualUserTest extends TestCase
{
    use RefreshDatabase, UsesAdminPages;

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    /** @return array<string, mixed> */
    private function input(string $role = 'member'): array
    {
        return [
            'name' => 'Nuevo usuario',
            'username' => 'new-user',
            'email' => 'new@example.com',
            'role' => $role,
            'password' => 'Temporary-Secret-123!',
            'password_confirmation' => 'Temporary-Secret-123!',
        ];
    }

    public function test_guest_and_member_cannot_create_users(): void
    {
        $this->get('/users/create')->assertRedirect('/login');
        $this->createPerson($this->input())->assertForbidden();
        $this->actingAs($this->actor('member'))->get('/users/create')->assertForbidden();
        $this->createPerson($this->input())->assertForbidden();
    }

    public function test_admin_creates_member_but_cannot_assign_privileged_roles(): void
    {
        $admin = $this->actor('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/users/create')->assertOk()->assertDontSee('value="admin"', false);
        $this->createPerson($this->input('admin'))->assertForbidden();
        $this->createPerson($this->input('owner'))->assertHasErrors('role');
        $this->createPerson($this->input())->assertHasNoErrors();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertSame('member', $user->role);
        $this->assertSame('new-user', $user->username);
        $this->assertSame($admin->id, $user->created_by_admin_id);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('Temporary-Secret-123!', $user->password));
        $this->assertDatabaseCount('service_accounts', 0);
        $this->createPerson($this->input())->assertHasErrors('email');
    }

    public function test_owner_can_create_admin_but_never_another_owner(): void
    {
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->createPerson($this->input('admin'))->assertHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'admin']);
        $this->createPerson([...$this->input('owner'), 'email' => 'another@example.com', 'username' => 'another-user'])->assertHasErrors('role');
    }

    public function test_manually_created_user_must_change_password_before_accessing_services(): void
    {
        config(['portal.services.music.enabled' => true, 'provisioning.music.enabled' => true]);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()])
            ->createPerson([...$this->input(), 'services' => ['music']])->assertHasNoErrors();
        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertDatabaseCount('service_accounts', 0);
        $this->post('/logout');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Temporary-Secret-123!'])
            ->assertRedirect(route('dashboard', absolute: false));
        $this->get('/dashboard')->assertRedirect(route('password.initial.edit'));
        $this->get('/services/music')->assertRedirect(route('password.initial.edit'));
        $this->get('/invitations')->assertRedirect(route('password.initial.edit'));
        $this->get(route('password.initial.edit'))->assertOk();
        $this->post(route('password.initial.update'), [
            'current_password' => 'bad', 'password' => 'New-Personal-Secret-123!', 'password_confirmation' => 'New-Personal-Secret-123!',
        ])->assertSessionHasErrors('current_password');
        $this->post(route('password.initial.update'), [
            'current_password' => 'Temporary-Secret-123!', 'password' => 'Temporary-Secret-123!', 'password_confirmation' => 'Temporary-Secret-123!',
        ])->assertSessionHasErrors('password');
        $this->post(route('password.initial.update'), [
            'current_password' => 'Temporary-Secret-123!', 'password' => 'New-Personal-Secret-123!', 'password_confirmation' => 'New-Personal-Secret-123!',
        ])->assertRedirect(route('dashboard'));
        $this->assertFalse($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('New-Personal-Secret-123!', $user->fresh()->password));
        $account = ServiceAccount::firstOrFail();
        $this->assertSame('music', $account->service);
        $this->assertNotNull($account->pending_password);
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('password.initial.edit'))->assertRedirect(route('dashboard'));
    }

    public function test_service_selection_is_server_validated_and_invitations_are_revoked(): void
    {
        $owner = $this->actor('owner');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->createPerson([...$this->input(), 'services' => ['unknown']])->assertHasErrors('services.0');
        $invitation = new Invitation;
        $invitation->email = 'new@example.com';
        $invitation->role = 'admin';
        $invitation->invited_by = $owner->id;
        $invitation->token_hash = hash('sha256', str_repeat('a', 64));
        $invitation->expires_at = now()->addDay();
        $invitation->save();
        $this->createPerson($this->input())->assertHasNoErrors();
        $this->assertTrue($invitation->fresh()->expires_at->isPast() || $invitation->fresh()->expires_at->isCurrentSecond());
    }

    public function test_admin_cannot_override_owner_invitation_or_mass_assign_owner(): void
    {
        $owner = $this->actor('owner');
        $admin = $this->actor('admin');
        $invitation = new Invitation;
        $invitation->email = 'new@example.com';
        $invitation->role = 'admin';
        $invitation->invited_by = $owner->id;
        $invitation->token_hash = hash('sha256', str_repeat('a', 64));
        $invitation->expires_at = now()->addDay();
        $invitation->save();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()])
            ->createPerson($this->input())->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'new@example.com']);
    }
}
