<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\KavitaAccounts;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Kavita adapter against an in-memory imitation of its API (same routes and rules as the
 * v0.9.1.4 controllers). The real server is exercised in tests/Integration/KavitaAccountsTest.
 */
class KavitaProvisioningTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    private array $users = [];

    private int $invites = 0;

    /** @var array<int, array<string, mixed>> */
    private array $libraries = [
        1 => ['id' => 1, 'name' => 'Libros', 'folders' => ['/books']],
        4 => ['id' => 4, 'name' => 'Documentación técnica', 'folders' => ['/docs']],
    ];

    /** @var list<int> */
    private array $scanned = [];

    private bool $passwordResetFails = false;

    private const TOKEN = 'to k+en/=';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.services.reading.enabled' => true,
            'provisioning.reading.enabled' => true,
            'provisioning.reading.url' => 'http://kavita.test',
            'provisioning.reading.username' => 'operator',
            'provisioning.reading.password' => 'operator-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->kavita($request));
    }

    /** Minimal Kavita: users, invitations, confirmation, roles and passwords. */
    private function kavita(Request $request)
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $data = $request->data();
        $member = fn (array $user): array => array_diff_key($user, ['password' => true, 'token' => true]);

        return match (true) {
            $path === '/api/Account/login' => $data['username'] === 'operator'
                ? Http::response(['token' => 'jwt', 'roles' => ['Admin', 'Login']])
                : $this->memberLogin((string) $data['username'], (string) $data['password']),
            $path === '/api/Users' => Http::response(array_values(array_map($member, $this->users))),
            $path === '/api/Library/libraries' => Http::response(array_values($this->libraries)),
            $path === '/api/Library/create' => $this->createLibrary($data),
            $path === '/api/Library/scan' => $this->scan((int) $query['libraryId']),
            $path === '/api/Account/invite' => $this->invite($data),
            $path === '/api/Account/invite-url' => Http::response(json_encode('registration/confirm-email?token='.urlencode(self::TOKEN).'&email='.urlencode($this->users[(int) $query['userId']]['email'])), 200, ['Content-Type' => 'application/json']),
            $path === '/api/Account/confirm-email' => $this->confirm($data),
            $path === '/api/Account/update' => $this->update($data),
            $path === '/api/Account/reset-password' => $this->resetPassword($data),
            $path === '/api/Users/delete-user' => $this->deleteMember((string) $query['username']),
            default => Http::response([], 404),
        };
    }

    /** @param array<string, mixed> $data */
    private function createLibrary(array $data)
    {
        foreach ($this->libraries as $library) {
            if ($library['name'] === $data['name']) {
                return Http::response('library-name-exists', 400);
            }
        }
        $id = max(array_keys($this->libraries)) + 1;
        $this->libraries[$id] = ['id' => $id, 'name' => $data['name'], 'folders' => $data['folders'], 'type' => $data['type']];

        return Http::response(['id' => $id]);
    }

    private function scan(int $library)
    {
        $this->scanned[] = $library;

        return Http::response();
    }

    private function memberLogin(string $username, string $password)
    {
        foreach ($this->users as $user) {
            if ($user['username'] === $username) {
                return in_array('Login', $user['roles'], true) && $user['password'] === $password
                    ? Http::response(['token' => 'member', 'roles' => $user['roles']])
                    : Http::response('disabled or bad credentials', 401);
            }
        }

        return Http::response('bad credentials', 401);
    }

    /** @param array<string, mixed> $data */
    private function invite(array $data)
    {
        $this->invites++;
        foreach ($this->users as $user) {
            if ($user['email'] === $data['email']) {
                return Http::response('user-already-invited', 400);
            }
        }
        $id = count($this->users) + 10;
        $this->users[$id] = [
            'id' => $id, 'username' => $data['email'], 'email' => $data['email'], 'isPending' => true,
            'roles' => [...$data['roles'], 'Pleb'], 'libraries' => array_map(fn (int $library): array => ['id' => $library], $data['libraries']),
            'identityProvider' => 0, 'ageRestriction' => $data['ageRestriction'], 'password' => '[k.2@RZ!mxCQkJzE', 'token' => self::TOKEN,
        ];

        return Http::response(['emailLink' => 'ignored', 'emailSent' => false]);
    }

    /** @param array<string, mixed> $data */
    private function confirm(array $data)
    {
        foreach ($this->users as $id => $user) {
            if ($user['email'] === $data['email']) {
                if ($user['token'] !== $data['token']) {
                    return Http::response('invalid-email-confirmation', 400);
                }
                if ($this->taken((string) $data['username'], $id)) {
                    return Http::response('username-taken', 400);
                }
                $this->users[$id] = ['username' => $data['username'], 'password' => $data['password'], 'isPending' => false, 'token' => null] + $user;

                return Http::response(['token' => 'member']);
            }
        }

        return Http::response('invalid-email-confirmation', 400);
    }

    /** @param array<string, mixed> $data */
    private function update(array $data)
    {
        $id = (int) $data['userId'];
        if ($this->taken((string) $data['username'], $id)) {
            return Http::response('username-taken', 400);
        }
        $roles = in_array('Admin', $data['roles'], true) ? $data['roles'] : array_values(array_unique([...$data['roles'], 'Pleb']));
        $this->users[$id] = ['username' => $data['username'], 'roles' => $roles, 'libraries' => array_map(fn (int $library): array => ['id' => $library], $data['libraries'])] + $this->users[$id];

        return Http::response();
    }

    /** @param array<string, mixed> $data */
    private function resetPassword(array $data)
    {
        if ($this->passwordResetFails) {
            return Http::response([], 503);
        }
        foreach ($this->users as $id => $user) {
            if ($user['username'] === $data['userName']) {
                $this->users[$id]['password'] = $data['password'];
            }
        }

        return Http::response();
    }

    private function deleteMember(string $username)
    {
        $this->users = array_filter($this->users, fn (array $user): bool => $user['username'] !== $username);

        return Http::response();
    }

    private function taken(string $username, int $except): bool
    {
        foreach ($this->users as $id => $user) {
            if ($id !== $except && $user['username'] === $username) {
                return true;
            }
        }

        return false;
    }

    private function reader(): User
    {
        $user = User::factory()->create(['email' => 'lectora@example.com']);
        $user->username = 'lectora';
        $user->service_access = ['reading'];
        $user->save();

        return $user;
    }

    /** @return array<string, mixed> */
    private function remote(ServiceAccount $account): array
    {
        foreach ($this->users as $user) {
            if ($user['email'] === KavitaAccounts::identity($account)) {
                return $user;
            }
        }
        $this->fail('Remote account not found.');
    }

    public function test_account_is_invited_without_sign_in_confirmed_with_the_password_and_then_enabled(): void
    {
        $user = $this->reader();
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'first-password');
        app(ServiceAccounts::class)->syncUser($user);

        $account = ServiceAccount::firstOrFail();
        $this->assertSame('ready', $account->status);
        $this->assertSame('lectora', $account->username);
        $remote = $this->remote($account);
        $this->assertSame(['lectora', 'first-password', false], [$remote['username'], $remote['password'], $remote['isPending']]);
        $this->assertContains('Login', $remote['roles']);
        $this->assertNotContains('Admin', $remote['roles']);
        $this->assertSame([1, 4], array_column($remote['libraries'], 'id'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/Account/invite')
            && ! in_array('Login', $request['roles'], true) && str_ends_with($request['email'], '@multisuite.invalid'));
        // The token arrives URL-encoded and is sent back decoded.
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/Account/confirm-email') && $request['token'] === self::TOKEN);
    }

    public function test_a_retry_after_a_lost_response_reuses_the_pending_invitation(): void
    {
        $user = $this->reader();
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'first-password');
        $account = ServiceAccount::firstOrFail();
        $this->invite(['email' => KavitaAccounts::identity($account), 'roles' => ['Download'], 'libraries' => [1], 'ageRestriction' => ['ageRating' => -1, 'includeUnknowns' => true]]);
        $this->invites = 0;

        app(ServiceAccounts::class)->synchronize($account->id);

        $this->assertSame('ready', $account->fresh()?->status);
        $this->assertSame(0, $this->invites);
        $this->assertSame(1, count($this->users));
    }

    public function test_a_kavita_administrator_or_someone_with_the_same_real_email_is_never_adopted(): void
    {
        $user = $this->reader();
        $this->users[1] = ['id' => 1, 'username' => 'lectora', 'email' => 'lectora@example.com', 'isPending' => false, 'roles' => ['Login', 'Pleb'],
            'libraries' => [], 'identityProvider' => 0, 'ageRestriction' => null, 'password' => 'theirs', 'token' => null];
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'first-password');
        app(ServiceAccounts::class)->syncUser($user);

        // Same name taken by someone else's account: conflict, and their password is untouched.
        $this->assertSame('conflict', ServiceAccount::firstOrFail()->status);
        $this->assertSame('theirs', $this->users[1]['password']);

        $account = ServiceAccount::firstOrFail();
        $this->users[2] = ['id' => 2, 'username' => 'otra', 'email' => KavitaAccounts::identity($account), 'isPending' => false, 'roles' => ['Admin', 'Login'],
            'libraries' => [], 'identityProvider' => 0, 'ageRestriction' => null, 'password' => 'admin', 'token' => null];
        app(ServiceAccounts::class)->changePassword($user, 'second-password');
        $this->assertSame('conflict', $account->fresh()?->status);
        $this->assertSame('admin', $this->users[2]['password']);
    }

    public function test_rename_keeps_the_password_lock_removes_sign_in_and_remove_deletes(): void
    {
        $user = $this->reader();
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'first-password');
        app(ServiceAccounts::class)->syncUser($user);
        $account = ServiceAccount::firstOrFail();
        $kavita = app(KavitaAccounts::class);

        $kavita->rename($account, 'lectora2');
        $this->assertSame(['lectora2', 'first-password'], [$this->remote($account)['username'], $this->remote($account)['password']]);

        $kavita->lock($account);
        $this->assertNotContains('Login', $this->remote($account)['roles']);
        $this->assertContains('Download', $this->remote($account)['roles']);
        $this->assertSame([1, 4], array_column($this->remote($account)['libraries'], 'id'));

        // Granting access again restores sign-in with the person's current password.
        $user->username = 'lectora2';
        $user->save();
        $kavita->synchronize($account, 'third-password');
        $this->assertContains('Login', $this->remote($account)['roles']);
        $this->assertSame('third-password', $this->remote($account)['password']);

        $kavita->remove($account);
        $this->assertSame([], $this->users);
        $kavita->remove($account);
    }

    public function test_a_locked_account_is_not_enabled_again_if_its_new_password_cannot_be_set(): void
    {
        $user = $this->reader();
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'leaked-password');
        app(ServiceAccounts::class)->syncUser($user);
        $account = ServiceAccount::firstOrFail();
        $kavita = app(KavitaAccounts::class);
        $kavita->lock($account);

        $this->passwordResetFails = true;
        try {
            $kavita->synchronize($account, 'new-password');
            $this->fail('The failed reset must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertSame('unavailable', $exception->getMessage());
        }

        $this->assertNotContains('Login', $this->remote($account)['roles']);
        $this->assertSame('leaked-password', $this->remote($account)['password']);
    }

    public function test_a_personal_library_is_created_for_its_owner_only_and_never_given_to_new_accounts(): void
    {
        $user = $this->reader();
        app(ServiceAccounts::class)->enroll($user, ['reading'], 'first-password');
        app(ServiceAccounts::class)->syncUser($user);
        $account = ServiceAccount::firstOrFail();
        $kavita = app(KavitaAccounts::class);

        $kavita->ensurePersonalLibrary($account, 'comics', 'Mis cómics');
        $kavita->ensurePersonalLibrary($account, 'comics', 'Mis cómics');

        $personal = array_values(array_filter($this->libraries, fn (array $library): bool => $library['folders'] === ['/personal/'.strtolower($account->remote_id).'/comics']));
        $this->assertCount(1, $personal);
        $this->assertSame(1, $personal[0]['type']);
        $this->assertContains($personal[0]['id'], array_column($this->remote($account)['libraries'], 'id'));
        $this->assertContains($personal[0]['id'], $this->scanned);

        // Someone invited afterwards gets the shared libraries, never another person's.
        $other = User::factory()->create(['email' => 'otra@example.com']);
        $other->username = 'otra';
        $other->service_access = ['reading'];
        $other->save();
        app(ServiceAccounts::class)->enroll($other, ['reading'], 'other-password');
        app(ServiceAccounts::class)->syncUser($other);
        $otherAccount = ServiceAccount::where('user_id', $other->id)->firstOrFail();
        $this->assertSame([1, 4], array_column($this->remote($otherAccount)['libraries'], 'id'));
    }

    public function test_reading_card_and_access_follow_the_same_rules_as_music(): void
    {
        config(['portal.services.reading.url' => 'https://lectura.example.test']);
        $user = $this->reader();
        $user->service_access = [];
        $user->save();
        $this->actingAs($user)->get('/services/reading')->assertForbidden();
        $this->assertSame(['music', 'reading'], array_keys(config('portal.services')));
        $this->assertContains('reading', app(ServiceAccounts::class)->available());
    }
}
