<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $previous = $user->email;
        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals($previous, $user->email);
        $this->assertNotNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_email_change_requires_recent_authentication_and_single_use_confirmation(): void
    {
        config(['mail.default' => 'array']);
        $user = User::factory()->create();
        $old = $user->email;
        $this->actingAs($user)->post(route('email-change.request'), ['email' => 'new@example.test'])
            ->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])->post(route('email-change.request'), ['email' => 'new@example.test'])->assertRedirect(route('profile.edit'));
        $this->assertSame($old, $user->fresh()->email);
        $body = app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
        $url = trim(explode("\n", trim($body))[2]);
        $this->get($url)->assertOk();
        $this->post($url)->assertRedirect(route('profile.edit'));
        $this->assertSame('new@example.test', $user->fresh()->email);
        $this->post($url)->assertGone();
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
