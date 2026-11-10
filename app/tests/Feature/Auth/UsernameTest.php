<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class UsernameTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_accepts_username_and_email_case_insensitively(): void
    {
        $user = User::factory()->create(['email' => 'tony@example.com']);
        $user->username = 'tony';
        $user->save();

        foreach (['TONY', 'TONY@EXAMPLE.COM'] as $identifier) {
            $this->post(route('login.store'), ['email' => $identifier, 'password' => 'password'])
                ->assertSessionHasNoErrors();
            $this->assertAuthenticatedAs($user);
            $this->post('/logout');
        }
        $this->post(route('login.store'), ['email' => 'tony', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_username_login_keeps_two_factor_challenge(): void
    {
        $this->skipUnlessFortifyHas(Features::twoFactorAuthentication());
        $user = User::factory()->withTwoFactor()->create();
        $user->username = 'tony-2fa';
        $user->save();
        $this->post(route('login.store'), ['email' => 'tony-2fa', 'password' => 'password'])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
    }

    public function test_both_identifiers_share_login_rate_limit(): void
    {
        $user = User::factory()->create();
        $user->username = 'tony';
        $user->save();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.store'), [
                'email' => $i % 2 === 0 ? 'tony' : $user->email,
                'password' => 'wrong',
            ]);
        }
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_existing_user_selects_username_once_with_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->post(route('username.update'), ['username' => 'tony', 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        foreach (['UPPER', 'ab', 'tony@example.com', 'has spaces'] as $invalid) {
            $this->post(route('username.update'), ['username' => $invalid, 'current_password' => 'password'])
                ->assertSessionHasErrors('username');
        }
        $this->post(route('username.update'), ['username' => 'tony', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertSame('tony', $user->fresh()->username);
        $this->post(route('username.update'), ['username' => 'another', 'current_password' => 'password'])
            ->assertSessionHasErrors('username');
        $other = User::factory()->create();
        $this->actingAs($other)->post(route('username.update'), ['username' => 'tony', 'current_password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertNull($other->fresh()->username);
    }
}
