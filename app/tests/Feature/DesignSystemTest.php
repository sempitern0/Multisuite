<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The theme layer renders the shell; authorisation still comes from gates and policies. */
class DesignSystemTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    public function test_members_only_see_their_own_navigation(): void
    {
        $this->actingAs($this->user('member'))->get(route('dashboard'))->assertOk()
            ->assertSee('data-layout="app"', false)
            ->assertSee('Servicios')
            ->assertSee(route('files.index'), false)
            ->assertDontSee('Administración')
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('storage.index'), false);
    }

    public function test_administrators_get_the_administration_group(): void
    {
        $this->actingAs($this->user('owner'))->get(route('dashboard'))->assertOk()
            ->assertSee('Administración')
            ->assertSee(route('users.index'), false)
            ->assertSee(route('invitations.index'), false)
            ->assertSee(route('storage.index'), false)
            ->assertSee(route('audit.index'), false);
    }

    public function test_navigation_is_filtered_by_gates_outside_the_theme(): void
    {
        $labels = fn (User $user): array => array_merge(...array_map(
            fn (array $group): array => array_column($group['items'], 'label'),
            Navigation::for($user)['groups'],
        ));

        $this->assertSame(['Servicios', 'Mis archivos'], $labels($this->user('member')));
        $this->assertSame(['Servicios', 'Mis archivos', 'Personas', 'Invitaciones', 'Almacenamiento', 'Actividad'], $labels($this->user('owner')));
        $this->assertSame('Miembro', Navigation::for($this->user('member'))['account']['role']);
    }

    public function test_managed_services_outside_the_invitation_are_hidden(): void
    {
        config(['portal.services.music.enabled' => true, 'portal.services.music.url' => 'https://music.example.test', 'provisioning.music.enabled' => true]);
        $user = $this->user('member');
        $user->service_access = [];
        $user->save();

        $this->actingAs($user)->get(route('dashboard'))->assertOk()
            ->assertDontSee('Música')
            ->assertSee('Archivos');
    }

    public function test_unconfigured_services_are_shown_as_coming_soon_without_a_link(): void
    {
        config(['portal.services.music.enabled' => false, 'provisioning.music.enabled' => false]);

        $this->actingAs($this->user('member'))->get(route('dashboard'))->assertOk()
            ->assertSee('Próximamente')
            ->assertDontSee(route('services.open', 'music'), false);
    }

    public function test_authentication_pages_use_the_theme_layout(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('data-layout="auth"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('data-test="login-button"', false);
    }
}
