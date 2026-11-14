<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Create, edit and delete people from Livewire pages: same Actions and rules as the HTTP endpoints. */
class AdminFormsTest extends TestCase
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

    private function member(): User
    {
        $user = User::factory()->create();
        $user->role = 'member';
        $user->save();

        return $user;
    }

    public function test_person_is_edited_in_place_and_email_change_needs_confirmation(): void
    {
        $member = $this->member();
        $owner = $this->actor('owner');

        $page = Livewire::test('pages::admin.user-edit', ['user' => $member])
            ->assertSet('name', $member->name)
            ->set('name', 'Nombre Nuevo')->set('role', 'admin')
            ->call('save')->assertHasNoErrors()->assertDispatched('ui-toast');
        $this->assertSame(['Nombre Nuevo', 'admin'], [$member->fresh()->name, $member->fresh()->role]);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'user.updated', 'actor_id' => $owner->id, 'subject_id' => $member->id]);

        $page->set('email', 'nuevo@example.com')->call('save')->assertHasErrors('email_confirmed');
        $this->assertNotSame('nuevo@example.com', $member->fresh()->email);
        $page->set('email_confirmed', true)->call('save')->assertHasNoErrors()->assertSet('email_confirmed', false);
        $this->assertSame('nuevo@example.com', $member->fresh()->email);
        $page->set('role', 'owner')->call('save')->assertHasErrors('role');
    }

    public function test_administrators_cannot_manage_owners_or_promote_from_the_page(): void
    {
        $owner = $this->member();
        $owner->role = 'owner';
        $owner->save();
        $member = $this->member();
        $this->actor('admin');

        Livewire::test('pages::admin.user-edit', ['user' => $owner])->assertForbidden();
        Livewire::test('pages::admin.user-edit', ['user' => $member])->set('role', 'admin')->call('save')->assertForbidden();
        $this->assertSame('member', $member->fresh()->role);
    }

    public function test_person_is_deleted_only_after_typing_their_email(): void
    {
        $member = $this->member();
        $this->actor('owner');

        $page = Livewire::test('pages::admin.user-edit', ['user' => $member]);
        $page->set('confirm_email', 'otro@example.com')->call('delete')->assertHasErrors('confirm_email');
        $this->assertNotNull($member->fresh());
        $page->set('confirm_email', $member->email)->call('delete')->assertRedirect(route('users.index'));
        $this->assertNull($member->fresh());
        $this->get(route('users.index'))->assertOk()->assertSee('Persona eliminada');
    }

    public function test_account_is_created_in_place_and_rejected_passwords_are_not_echoed(): void
    {
        $admin = $this->actor('admin');
        $page = Livewire::test('pages::admin.user-create')
            ->assertDontSeeHtml('value="admin"')
            ->set('name', 'Persona Nueva')->set('email', 'nueva@example.com')
            ->set('password', 'corta')->set('password_confirmation', 'corta')
            ->call('create')->assertHasErrors('password')->assertSet('password', '')->assertSet('password_confirmation', '');
        $this->assertDatabaseMissing('users', ['email' => 'nueva@example.com']);

        $page->set('password', 'Temporal-Segura-2026!')->set('password_confirmation', 'Temporal-Segura-2026!')
            ->call('create')->assertHasNoErrors()->assertDispatched('ui-toast')->assertSet('email', '')->assertSet('password', '');
        $user = User::where('email', 'nueva@example.com')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->username);
        $this->assertSame($admin->id, $user->created_by_admin_id);
        $this->assertTrue(Hash::check('Temporal-Segura-2026!', $user->password));

        $page->set('name', 'Otra')->set('email', 'otra@example.com')->set('role', 'admin')
            ->set('password', 'Temporal-Segura-2026!')->set('password_confirmation', 'Temporal-Segura-2026!')
            ->call('create')->assertForbidden();
    }
}
