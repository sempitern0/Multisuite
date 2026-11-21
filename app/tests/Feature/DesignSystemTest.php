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
            ->assertSee('data-scheme-toggle', false)
            ->assertSee('Servicios')
            ->assertSee(route('files.index'), false)
            ->assertDontSee('Administración')
            ->assertDontSee(route('users.index'), false)
            ->assertDontSee(route('storage.index'), false);
    }

    public function test_checkbox_rows_switches_and_role_badges_keep_their_accessible_contract(): void
    {
        $this->withViewErrors([]);
        $this->blade('<x-ui::checkbox switch name="enabled" label="Activado" description="Nadie puede usarlo apagado." />')
            ->assertSee('role="switch"', false)
            ->assertSee('aria-describedby="chk-enabled-1-desc"', false)
            ->assertSee('id="chk-enabled-1-desc"', false);
        $this->blade('<x-ui::checkbox name="tools[]" value="files_list" label="Archivos" />')
            ->assertDontSee('role="switch"', false)->assertDontSee('aria-describedby', false);
        $this->blade('<x-ui::role role="admin" :level="2">Administrador</x-ui::role>')
            ->assertSee('data-role="admin"', false)->assertSee('nivel de permisos 2 de 3');

        $this->assertSame([3, 2, 1], [
            User::factory()->make(['role' => 'owner'])->roleLevel(),
            User::factory()->make(['role' => 'admin'])->roleLevel(),
            User::factory()->make(['role' => 'member'])->roleLevel(),
        ]);
    }

    public function test_the_light_and_dark_switch_is_also_on_the_sign_in_page(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('data-scheme-toggle', false);
    }

    public function test_theme_colours_come_from_the_tokens_so_both_schemes_work(): void
    {
        $css = (string) file_get_contents(resource_path('css/themes/nocturne.css'));
        // The Flux scale in @theme is a fixed palette; image overlays, QR and PDF stay black or white.
        preg_match_all('/#[0-9a-fA-F]{3,8}\b/', (string) preg_replace('/@theme \{.*?\n\}/s', '', $css), $matches);

        $this->assertSame([], array_values(array_diff(array_unique($matches[0]), ['#fff', '#000', '#000a'])));
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

        $this->assertSame(['Servicios', 'Mis archivos', 'Mis gastos'], $labels($this->user('member')));
        $this->assertSame(['Servicios', 'Mis archivos', 'Mis gastos', 'Personas', 'Invitaciones', 'Almacenamiento', 'Ajustes del asistente', 'Actividad'], $labels($this->user('owner')));
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
