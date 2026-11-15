<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_save_on_change_and_apply_without_reloading(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.preferences')
            ->assertSet('motion', 'system')
            ->set('motion', 'on')
            ->assertHasNoErrors()
            ->assertDispatched('ui-preferences', attributes: ['data-motion' => 'on', 'data-density' => 'comfortable', 'data-sidebar' => 'full'])
            ->assertDispatched('ui-toast')
            ->set('per_page', '50');

        $preferences = $user->fresh()->preferences();
        $this->assertSame('on', $preferences->motion());
        $this->assertSame(50, $preferences->perPage());
        $this->get(route('dashboard'))->assertSee('data-motion="on"', false);
    }

    public function test_unknown_values_are_rejected_and_bad_stored_values_fall_back(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test('pages::settings.preferences')->set('density', 'gigantic')->assertHasErrors('density');
        $this->assertNull($user->fresh()->preferences);

        $this->assertSame(UserPreferences::defaults()->toArray(), UserPreferences::from(['motion' => 'wild', 'per_page' => 999])->toArray());
    }

    public function test_collapse_button_remembers_the_side_menu_without_touching_other_preferences(): void
    {
        $user = User::factory()->create(['preferences' => ['density' => 'compact']]);
        $this->actingAs($user)->get(route('dashboard'))->assertSee('data-sidebar-toggle', false)->assertSee('aria-expanded="true"', false);

        $this->putJson(route('preferences.sidebar'), ['sidebar' => 'compact'])->assertNoContent();
        $this->assertSame('compact', $user->fresh()->preferences()->sidebar());
        $this->assertSame('compact', $user->fresh()->preferences()->density());
        $this->get(route('dashboard'))->assertSee('data-sidebar="compact"', false)->assertSee('aria-expanded="false"', false);

        $this->putJson(route('preferences.sidebar'), ['sidebar' => 'hidden'])->assertUnprocessable();
        // The Preferences form no longer offers it and keeps the button's choice.
        Livewire::test('pages::settings.preferences')->assertDontSee('Menú lateral')->set('motion', 'off');
        $this->assertSame('compact', $user->fresh()->preferences()->sidebar());
        auth()->logout();
        $this->putJson(route('preferences.sidebar'), ['sidebar' => 'full'])->assertUnauthorized();
    }

    public function test_sign_in_goes_to_the_chosen_start_page(): void
    {
        $user = User::factory()->create(['preferences' => ['start' => 'files']]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('files.index'));
    }

    public function test_old_appearance_address_redirects_to_preferences(): void
    {
        $this->actingAs(User::factory()->create())->get('/settings/appearance')->assertRedirect('/settings/preferences');
    }
}
