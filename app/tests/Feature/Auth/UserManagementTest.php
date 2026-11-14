<?php

namespace Tests\Feature\Auth;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\UsesAdminPages;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase, UsesAdminPages;

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    /** @return array<string, string> */
    private function input(User $user): array
    {
        return ['name' => 'Updated name', 'email' => $user->email, 'role' => $user->role];
    }

    public function test_only_administrators_can_access_users_and_password_confirmation_is_required(): void
    {
        $target = $this->actor('member');
        $this->get('/users')->assertRedirect('/login');
        $this->actingAs($target)->get('/users')->assertForbidden();
        $this->get('/users/'.$target->id.'/edit')->assertForbidden();
        $this->updatePerson($target, $this->input($target))->assertForbidden();
        $this->deletePerson($target)->assertForbidden();
        $this->get('/dashboard')->assertDontSee('href="'.route('users.index').'"', false);
        $this->actingAs($this->actor('admin'))->get('/users')->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])->get('/users')->assertOk()->assertSee($target->email);
    }

    public function test_administrator_can_edit_member_but_not_privileged_users_or_promote(): void
    {
        $admin = $this->actor('admin');
        $member = $this->actor('member');
        $owner = $this->actor('owner');
        $peer = $this->actor('admin');
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => time()]);
        foreach ([$owner, $admin, $peer] as $target) {
            $this->updatePerson($target, $this->input($target))->assertForbidden();
            $this->deletePerson($target, $target->email)->assertForbidden();
        }
        $this->get('/users/'.$member->id.'/edit')->assertOk();
        $this->updatePerson($member, [...$this->input($member), 'role' => 'admin'])->assertForbidden();
        $this->updatePerson($member, $this->input($member))->assertHasNoErrors();
        $this->assertSame('Updated name', $member->fresh()->name);
    }

    public function test_owner_can_manage_admin_but_cannot_modify_owners_or_assign_owner(): void
    {
        $owner = $this->actor('owner');
        $admin = $this->actor('admin');
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()]);
        $this->updatePerson($admin, [...$this->input($admin), 'role' => 'member'])->assertHasNoErrors();
        $this->assertSame('member', $admin->fresh()->role);
        $this->updatePerson($admin, [...$this->input($admin), 'role' => 'owner'])->assertHasErrors('role');
        $this->get('/users/'.$owner->id.'/edit')->assertForbidden();
        $this->deletePerson($owner, $owner->email)->assertForbidden();
    }

    public function test_email_change_requires_ownership_confirmation_and_revokes_reset_token(): void
    {
        $member = $this->actor('member');
        $token = Password::createToken($member);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $input = [...$this->input($member), 'email' => 'changed@example.com'];
        $this->updatePerson($member, $input)->assertHasErrors('email_confirmed');
        $this->updatePerson($member, [...$input, 'email_confirmed' => '1'])->assertHasNoErrors();
        $this->assertFalse(Password::tokenExists($member, $token));
        $this->assertSame('changed@example.com', $member->fresh()->email);
    }

    public function test_search_and_delete_require_exact_target_confirmation(): void
    {
        $member = $this->actor('member');
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->get('/users?q='.urlencode($member->email))->assertOk()->assertSee($member->email);
        $this->get('/users?q=nonexistent-user-query')->assertOk()->assertDontSee($member->email);
        $this->deletePerson($member, 'wrong')->assertHasErrors('confirm_email');
        $this->assertModelExists($member);
        $this->deletePerson($member, $member->email)->assertRedirect(route('users.index'));
        $this->assertModelMissing($member);
    }

    private function musicAccount(User $member): ServiceAccount
    {
        config(['provisioning.music.url' => 'http://music.test']);
        Http::preventStrayRequests();
        app(ServiceAccounts::class)->enroll($member, ['music'], 'test-secret');

        return ServiceAccount::where('user_id', $member->id)->firstOrFail();
    }

    public function test_remote_failure_preserves_local_account_for_retry(): void
    {
        $member = $this->actor('member');
        $account = $this->musicAccount($member);
        Http::fake(['*' => Http::response([], 503)]);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->deletePerson($member, $member->email)->assertHasErrors('deletion');
        $this->assertModelExists($member);
        $this->assertModelExists($account);
    }

    public function test_verified_remote_account_is_deleted_before_local_account(): void
    {
        $member = $this->actor('member');
        $account = $this->musicAccount($member);
        Http::fake([
            'music.test/auth/login' => Http::response(['token' => 'token', 'isAdmin' => true]),
            'music.test/api/user/'.$account->remote_id => Http::sequence()
                ->push(['id' => $account->remote_id, 'userName' => $account->username, 'isAdmin' => false])
                ->push([], 204)->push([], 404),
        ]);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->deletePerson($member, $member->email)->assertRedirect(route('users.index'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), $account->remote_id));
        $this->assertModelMissing($member);
        $this->assertModelMissing($account);
    }

    public function test_remote_administrator_is_never_deleted(): void
    {
        $member = $this->actor('member');
        $account = $this->musicAccount($member);
        Http::fake([
            'music.test/auth/login' => Http::response(['token' => 'token', 'isAdmin' => true]),
            'music.test/api/user/'.$account->remote_id => Http::response(['id' => $account->remote_id, 'userName' => $account->username, 'isAdmin' => true]),
        ]);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->deletePerson($member, $member->email)->assertHasErrors('deletion');
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
        $this->assertModelExists($member);
    }

    public function test_missing_remote_account_allows_idempotent_local_cleanup(): void
    {
        $member = $this->actor('member');
        $this->musicAccount($member);
        Http::fake([
            'music.test/auth/login' => Http::response(['token' => 'token', 'isAdmin' => true]),
            'music.test/api/user/*' => Http::response([], 404),
        ]);
        $this->actingAs($this->actor('owner'))->withSession(['auth.password_confirmed_at' => time()]);
        $this->deletePerson($member, $member->email)->assertRedirect(route('users.index'));
        $this->assertModelMissing($member);
    }
}
