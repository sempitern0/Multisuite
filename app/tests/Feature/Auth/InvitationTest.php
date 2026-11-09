<?php

namespace Tests\Feature\Auth;

use App\Actions\Fortify\CreateNewUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_without_an_invitation_is_rejected(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Guest', 'email' => 'guest@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
        ])->assertSessionHasErrors('invitation');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest();
    }

    public function test_invalid_expired_used_and_wrong_email_codes_are_rejected(): void
    {
        foreach (['invalid', 'expired', 'used', 'wrong-email'] as $case) {
            DB::table('invitations')->delete();
            DB::table('invitations')->insert([
                'email' => $case === 'wrong-email' ? 'other@example.com' : 'guest@example.com',
                'token_hash' => hash('sha256', str_repeat('a', 64)),
                'expires_at' => $case === 'expired' ? now()->subMinute() : now()->addDay(),
                'used_at' => $case === 'used' ? now() : null,
            ]);
            $this->post(route('register.store'), [
                'name' => 'Guest', 'email' => 'guest@example.com',
                'password' => 'password', 'password_confirmation' => 'password',
                'invitation' => str_repeat($case === 'invalid' ? 'b' : 'a', 64),
            ])->assertSessionHasErrors('invitation');
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_successful_registration_consumes_the_code_permanently(): void
    {
        DB::table('invitations')->insert([
            'email' => 'guest@example.com',
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => now()->addDay(),
        ]);
        $input = [
            'name' => 'Guest', 'email' => 'guest@example.com',
            'password' => 'password', 'password_confirmation' => 'password',
            'invitation' => str_repeat('a', 64),
        ];
        $action = app(CreateNewUser::class);
        $user = $action->create($input);
        $this->assertNotNull(DB::table('invitations')->value('used_at'));
        $user->delete();

        $this->expectException(ValidationException::class);
        $action->create($input);
    }

    public function test_validation_failure_preserves_the_code_and_does_not_flash_it(): void
    {
        DB::table('invitations')->insert([
            'email' => 'guest@example.com',
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'expires_at' => now()->addDay(),
        ]);
        $this->post(route('register.store'), [
            'name' => 'Guest', 'email' => 'guest@example.com',
            'password' => 'password', 'password_confirmation' => 'different',
            'invitation' => str_repeat('a', 64),
        ])->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.invitation');

        $this->assertNull(DB::table('invitations')->value('used_at'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_operator_can_issue_replace_and_revoke_an_invitation(): void
    {
        $this->artisan('portal:invite', ['email' => 'guest@example.com'])->assertSuccessful();
        $first = DB::table('invitations')->value('token_hash');
        $this->artisan('portal:invite', ['email' => 'guest@example.com'])->assertSuccessful();
        $this->assertDatabaseCount('invitations', 1);
        $this->assertNotSame($first, DB::table('invitations')->value('token_hash'));
        $this->artisan('portal:invite', ['email' => 'guest@example.com', '--revoke' => true])->assertSuccessful();
        $this->assertDatabaseCount('invitations', 0);
    }
}
