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
