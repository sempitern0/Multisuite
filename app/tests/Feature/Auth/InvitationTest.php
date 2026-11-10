<?php

namespace Tests\Feature\Auth;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\PortalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function invitation(User $issuer, string $role = 'member'): string
    {
        $invitation = new Invitation;
        $invitation->email = 'guest@example.com';
        $invitation->role = $role;
        $invitation->invited_by = $issuer->id;
        $invitation->token_hash = hash('sha256', str_repeat('a', 64));
        $invitation->expires_at = now()->addDay();
        $invitation->save();

        return URL::temporarySignedRoute('invitation.accept', now()->addDay(), ['token' => str_repeat('a', 64)]);
    }

    /** @return array<string, string> */
    private function details(): array
    {
        return ['name' => 'Guest', 'password' => 'password', 'password_confirmation' => 'password'];
    }

    public function test_invited_user_can_select_a_username(): void
    {
        $url = $this->invitation($this->actor('owner'));
        $this->post($url, [...$this->details(), 'username' => 'guest-user'])->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'guest@example.com', 'username' => 'guest-user']);
    }

    public function test_only_authorized_roles_can_send_and_assign_invitations(): void
    {
        Notification::fake();
        $this->get('/invitations')->assertRedirect('/login');
        $member = $this->actor('member');
        $this->actingAs($member)->post('/invitations', ['email' => 'guest@example.com', 'role' => 'member'])->assertForbidden();
        $admin = $this->actor('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/invitations', ['email' => 'guest@example.com', 'role' => 'admin'])->assertForbidden();
        $this->post('/invitations', ['email' => 'guest@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->post('/invitations', ['email' => 'guest@example.com', 'role' => 'member'])->assertRedirect();
        Notification::assertSentOnDemand(PortalInvitation::class);
        $this->assertDatabaseHas('invitations', ['email' => 'guest@example.com', 'role' => 'member', 'invited_by' => $admin->id]);
    }

    public function test_owner_can_invite_admin_but_admin_cannot_overwrite_or_revoke_it(): void
    {
        Notification::fake();
        $owner = $this->actor('owner');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->post('/invitations', ['email' => 'guest@example.com', 'role' => 'admin'])->assertRedirect();
        $this->get('/invitations')->assertOk();
        $id = Invitation::firstOrFail()->id;
        $this->actingAs($this->actor('admin'));
        $this->post('/invitations', ['email' => 'guest@example.com', 'role' => 'member'])->assertForbidden();
        $this->delete('/invitations/'.$id)->assertForbidden();
        $this->get('/invitations')->assertOk()->assertDontSee('guest@example.com');
    }

    public function test_signed_link_cannot_be_altered_or_used_without_a_signature(): void
    {
        $url = $this->invitation($this->actor('owner'));
        $this->get($url)->assertOk();
        $this->get($url.'&role=owner')->assertForbidden();
        $this->post($url.'&email=other@example.com', $this->details())->assertForbidden();
        $this->get('/invitations/accept/'.str_repeat('a', 64))->assertForbidden();
        $this->post(str_replace(str_repeat('a', 64), str_repeat('b', 64), $url), $this->details())->assertForbidden();
    }

    public function test_acceptance_uses_server_role_and_email_and_rejects_injected_fields(): void
    {
        $url = $this->invitation($this->actor('owner'), 'admin');
        $this->post($url, [...$this->details(), 'role' => 'owner', 'email' => 'other@example.com'])->assertSessionHasErrors(['role', 'email']);
        $this->assertNull(Invitation::firstOrFail()->used_at);
        $this->post($url, $this->details())->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('users', ['email' => 'guest@example.com', 'role' => 'admin']);
        $this->assertAuthenticated();
        $this->post('/logout');
        User::where('email', 'guest@example.com')->delete();
        $this->post($url, $this->details())->assertStatus(410);
    }

    public function test_expiration_revocation_and_issuer_demotion_invalidate_links(): void
    {
        $owner = $this->actor('owner');
        $url = $this->invitation($owner, 'admin');
        $owner->role = 'member';
        $owner->save();
        $this->get($url)->assertStatus(410);
        $this->post($url, $this->details())->assertStatus(410);
        $owner->role = 'owner';
        $owner->save();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/invitations/'.Invitation::firstOrFail()->id)->assertRedirect();
        $this->post('/logout');
        $this->post($url, $this->details())->assertStatus(410);
        $this->travel(2)->days();
        $this->get($url)->assertForbidden();
    }

    public function test_reissue_invalidates_the_old_link_and_failed_validation_preserves_it(): void
    {
        Notification::fake();
        $owner = $this->actor('owner');
        $url = $this->invitation($owner);
        $this->post($url, [...$this->details(), 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->assertNull(Invitation::firstOrFail()->used_at);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->post('/invitations', ['email' => 'guest@example.com', 'role' => 'member'])->assertRedirect();
        $this->post('/logout');
        $this->get($url)->assertNotFound();
    }

    public function test_role_is_not_mass_assignable_and_owner_bootstrap_is_console_only(): void
    {
        $member = $this->actor('member');
        $member->fill(['role' => 'owner'])->save();
        $this->assertSame('member', $member->fresh()?->role);
        $this->artisan('portal:owner', ['email' => $member->email])->assertSuccessful();
        $this->artisan('portal:owner', ['email' => $member->email])->assertFailed();
        $this->assertSame('owner', $member->fresh()?->role);
    }
}
