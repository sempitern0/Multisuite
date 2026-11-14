<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\PortalInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/** Livewire list pages: same authorisation as before, but no page reloads. */
class AdminListsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);

        return $user;
    }

    public function test_members_cannot_use_the_list_components_directly(): void
    {
        $this->actor('member');

        Livewire::test('pages::admin.users')->assertForbidden();
        Livewire::test('pages::admin.invitations')->assertForbidden();
        Livewire::test('pages::admin.activity')->assertForbidden();
    }

    public function test_people_search_filters_without_reloading_and_paginates(): void
    {
        $owner = $this->actor('owner');
        $owner->preferences = ['per_page' => 10];
        $owner->save();
        User::factory()->count(12)->create();
        $match = User::factory()->create(['name' => 'Zoe Buscada', 'email' => 'zoe@example.com']);

        $component = Livewire::test('pages::admin.users');
        $this->assertSame(10, substr_count($component->html(), 'wire:key="user-'));
        $component->assertSee('Página 1 de 2')
            ->set('search', 'zoe@')
            ->assertSee($match->email)
            ->assertDontSee('Página 1 de 2');
        $this->assertSame(1, substr_count($component->html(), 'wire:key="user-'));
    }

    public function test_invitations_are_sent_and_revoked_in_place(): void
    {
        Notification::fake();
        $this->actor('admin');

        Livewire::test('pages::admin.invitations')
            ->set('email', 'guest@example.com')
            ->call('invite')
            ->assertHasNoErrors()
            ->assertDispatched('ui-toast')
            ->assertSet('email', '')
            ->assertSee('guest@example.com');

        $invitation = Invitation::where('email', 'guest@example.com')->firstOrFail();
        $this->assertSame('member', $invitation->role);
        Notification::assertSentOnDemand(PortalInvitation::class);

        Livewire::test('pages::admin.invitations')
            ->call('revoke', $invitation->id)
            ->assertDispatched('ui-toast');
        $this->assertTrue($invitation->fresh()->expires_at->isPast());
    }

    public function test_administrators_cannot_invite_or_revoke_administrators_from_the_component(): void
    {
        Notification::fake();
        $owner = User::factory()->create(['role' => 'owner']);
        $adminInvitation = new Invitation;
        $adminInvitation->email = 'boss@example.com';
        $adminInvitation->role = 'admin';
        $adminInvitation->services = [];
        $adminInvitation->invited_by = $owner->id;
        $adminInvitation->token_hash = hash('sha256', 'token');
        $adminInvitation->expires_at = now()->addDay();
        $adminInvitation->save();
        $this->actor('admin');

        Livewire::test('pages::admin.invitations')
            ->set('email', 'other@example.com')->set('role', 'admin')
            ->call('invite')
            ->assertForbidden();
        // Owner-issued administrator invitations are not even listed for administrators.
        Livewire::test('pages::admin.invitations')
            ->assertDontSee('boss@example.com')
            ->call('revoke', $adminInvitation->id)
            ->assertNotFound();
        $this->assertTrue($adminInvitation->fresh()->expires_at->isFuture());
    }

    public function test_invitation_validation_errors_stay_next_to_the_field(): void
    {
        $this->actor('owner');
        $existing = User::factory()->create();

        Livewire::test('pages::admin.invitations')
            ->set('email', $existing->email)
            ->call('invite')
            ->assertHasErrors(['email' => 'unique']);
    }

    public function test_livewire_actions_share_the_admin_rate_limit(): void
    {
        Notification::fake();
        $this->actor('owner');
        $component = Livewire::test('pages::admin.invitations');

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $component->set('email', "guest{$attempt}@example.com")->call('invite')->assertHasNoErrors();
        }
        $component->set('email', 'one-too-many@example.com')->call('invite')->assertStatus(429);
        $this->assertDatabaseMissing('invitations', ['email' => 'one-too-many@example.com']);
    }

    public function test_invitation_status_follows_the_model_rule_in_list_and_filters(): void
    {
        $owner = $this->actor('owner');
        $former = User::factory()->create(['role' => 'member']);
        $make = function (string $email, User $issuer, array $changes = []): Invitation {
            $invitation = new Invitation;
            $invitation->email = $email;
            $invitation->role = 'member';
            $invitation->services = [];
            $invitation->invited_by = $issuer->id;
            $invitation->token_hash = hash('sha256', $email);
            $invitation->expires_at = now()->addDay();
            foreach ($changes as $key => $value) {
                $invitation->{$key} = $value;
            }
            $invitation->save();

            return $invitation;
        };
        $pending = $make('pending@example.com', $owner);
        // Issued by someone who can no longer invite: the link does not work, so it is not pending.
        $orphan = $make('orphan@example.com', $former);
        $make('accepted@example.com', $owner, ['used_at' => now()]);
        $make('expired@example.com', $owner, ['expires_at' => now()->subMinute()]);

        $this->assertSame('pending', $pending->status());
        $this->assertSame('expired', $orphan->status());
        $emails = fn (string $status): array => Invitation::status($status)->orderBy('email')->pluck('email')->all();
        $this->assertSame(['pending@example.com'], $emails('pending'));
        $this->assertSame(['accepted@example.com'], $emails('accepted'));
        $this->assertSame(['expired@example.com', 'orphan@example.com'], $emails('expired'));
        $this->assertCount(4, $emails(''));

        Livewire::withQueryParams(['status' => 'pending'])->test('pages::admin.invitations')
            ->assertSee('pending@example.com')
            ->assertDontSee('orphan@example.com');
        Livewire::test('pages::admin.invitations')
            ->assertSeeHtml('aria-label="Revocar invitación de pending@example.com"')
            ->assertDontSeeHtml('aria-label="Revocar invitación de orphan@example.com"');
    }
}
