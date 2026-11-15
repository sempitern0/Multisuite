<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Username changes, per-service access, suspension and profile photos: each one goes through
 * a single Action (the pages are adapters), is authorised between two people and keeps the
 * service and Multisuite consistent when the service fails.
 */
class UserAdministrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> state of the fake remote account */
    private array $remote = [];

    private bool $remoteDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'portal.services.music.enabled' => true,
            'portal.services.music.url' => 'https://music.example.test',
            'provisioning.music.enabled' => true,
            'provisioning.music.url' => 'http://music.test',
            'provisioning.music.username' => 'operator',
            'provisioning.music.password' => 'admin-secret',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($this->remoteDown) {
                return Http::response([], 503);
            }
            if (str_ends_with($request->url(), '/auth/login')) {
                return Http::response(['token' => 'token', 'isAdmin' => true]);
            }
            if ($this->remote === []) {
                return Http::response([], 404);
            }
            if ($request->method() === 'PUT') {
                $this->remote = [...$this->remote, ...array_intersect_key($request->data(), ['userName' => true])];
            }

            return Http::response($this->remote);
        });
    }

    private function person(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function actAs(User $user): void
    {
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    /** A member with a ready music account whose remote side exists. */
    private function memberWithMusic(): User
    {
        $member = $this->person('member');
        $member->service_access = ['music'];
        $member->save();
        $account = new ServiceAccount;
        $account->user_id = $member->id;
        $account->service = 'music';
        $account->remote_id = '0b6c8d3e-2f1a-4c55-9e1f-8b2a7d6c5e4f';
        $account->username = $member->email;
        $account->status = 'ready';
        $account->save();
        $this->remote = ['id' => $account->remote_id, 'userName' => $member->email, 'isAdmin' => false, 'scrobbleFilter' => '{}'];

        return $member;
    }

    private function page(User $target)
    {
        return Livewire::test('pages::admin.user-edit', ['user' => User::findOrFail($target->id)]);
    }

    public function test_username_is_changed_in_the_service_first_without_touching_its_password(): void
    {
        $this->actAs($this->person('admin'));
        $member = $this->memberWithMusic();

        $this->page($member)->set('username', 'nuevo.nombre')->call('saveUsername')->assertHasNoErrors();

        $this->assertSame('nuevo.nombre', $member->fresh()->username);
        $this->assertSame('nuevo.nombre', ServiceAccount::firstOrFail()->username);
        $this->assertSame('nuevo.nombre', $this->remote['userName']);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
            && $request['userName'] === 'nuevo.nombre' && ! isset($request['password']) && $request['scrobbleFilter'] === '{}');
        $this->assertSame('user.username_changed', AdminAuditEvent::latest('id')->value('action'));
    }

    public function test_username_change_keeps_everything_when_the_service_does_not_answer(): void
    {
        $this->actAs($this->person('admin'));
        $member = $this->memberWithMusic();
        $this->remoteDown = true;

        $this->page($member)->set('username', 'nuevo.nombre')->call('saveUsername')->assertHasErrors('username');

        $this->assertNull($member->fresh()->username);
        $this->assertSame($member->email, ServiceAccount::firstOrFail()->username);
    }

    public function test_administrators_cannot_manage_owners_or_other_administrators(): void
    {
        $this->actAs($this->person('admin'));
        $this->page($this->person('admin'))->assertForbidden();
        $this->page($this->person('owner'))->assertForbidden();
    }

    public function test_disabling_a_service_locks_it_remotely_and_enabling_waits_for_the_next_sign_in(): void
    {
        $this->actAs($this->person('owner'));
        $member = $this->memberWithMusic();

        $this->page($member)->call('setService', 'music', false)->assertHasNoErrors();

        $account = ServiceAccount::firstOrFail();
        $this->assertSame(ServiceAccounts::LOCKED, $account->status);
        $this->assertSame([], $member->fresh()->service_access);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && strlen((string) $request['password']) === 64);
        $this->actingAs($member->fresh())->get('/services/music')->assertForbidden();

        // A password change while disabled never reaches the service.
        app(ServiceAccounts::class)->changePassword($member->fresh(), 'another-password');
        $this->assertNull($account->fresh()->pending_password);
        $this->assertSame(ServiceAccounts::LOCKED, $account->fresh()->status);

        $this->actAs(User::where('role', 'owner')->firstOrFail());
        $this->page($member)->call('setService', 'music', true)->assertHasNoErrors();
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, $account->fresh()->status);
        $this->assertSame('service.access_granted', AdminAuditEvent::latest('id')->value('action'));
    }

    public function test_awaiting_service_is_activated_by_signing_in_with_the_password(): void
    {
        $member = $this->memberWithMusic();
        $member->password = 'right-password';
        $member->save();
        ServiceAccount::query()->update(['status' => ServiceAccounts::AWAITING_PASSWORD]);

        $this->withoutDefer()->post(route('login.store'), ['email' => $member->email, 'password' => 'right-password'])->assertRedirect();

        $this->assertSame('ready', ServiceAccount::firstOrFail()->status);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && $request['password'] === 'right-password');
    }

    public function test_service_stays_enabled_when_the_lock_cannot_be_confirmed(): void
    {
        $this->actAs($this->person('admin'));
        $member = $this->memberWithMusic();
        $this->remoteDown = true;

        $this->page($member)->call('setService', 'music', false)->assertHasErrors('services');

        $this->assertSame(['music'], $member->fresh()->service_access);
        $this->assertSame('ready', ServiceAccount::firstOrFail()->status);
    }

    public function test_suspension_blocks_sign_in_closes_sessions_and_locks_services(): void
    {
        $owner = $this->person('owner');
        $member = $this->memberWithMusic();
        $member->password = 'right-password';
        $member->save();

        $this->actAs($owner);
        $this->page($member)->call('setSuspended', true)->assertHasNoErrors();

        $this->assertTrue($member->fresh()->isSuspended());
        $this->assertSame(ServiceAccounts::LOCKED, ServiceAccount::firstOrFail()->status);
        $this->actingAs($member->fresh())->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $member->email, 'password' => 'right-password'])->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actAs($owner);
        $this->page($member)->call('setSuspended', false)->assertHasNoErrors();
        $this->assertFalse($member->fresh()->isSuspended());
        $this->assertSame(ServiceAccounts::AWAITING_PASSWORD, ServiceAccount::firstOrFail()->status);
        $this->assertSame('user.reactivated', AdminAuditEvent::latest('id')->value('action'));
    }

    public function test_a_person_uploads_and_replaces_their_own_photo_which_is_stored_small_and_private(): void
    {
        Storage::fake('local');
        $member = $this->person('member');
        $this->actingAs($member);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('foto.jpg', 900, 600))
            ->call('saveAvatar')->assertHasNoErrors()->assertDispatched('avatar-changed');

        $first = $member->fresh()->avatar_hash;
        $this->assertNotNull($first);
        $path = 'avatars/'.$member->id.'/'.$first.'.webp';
        Storage::disk('local')->assertExists($path);
        $size = getimagesizefromstring((string) Storage::disk('local')->get($path));
        $this->assertSame([256, 256, IMAGETYPE_WEBP], [$size[0] ?? 0, $size[1] ?? 0, $size[2] ?? 0]);

        $this->get((string) $member->fresh()->avatarUrl())->assertOk()
            ->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->image('otra.png', 300, 300))
            ->call('saveAvatar')->assertHasNoErrors();
        $this->assertNotSame($first, $member->fresh()->avatar_hash);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('avatars.show', ['user' => $member->id, 'hash' => $first]))->assertNotFound();
    }

    public function test_photos_that_are_not_images_are_rejected(): void
    {
        Storage::fake('local');
        $member = $this->person('member');
        $this->actingAs($member);

        Livewire::test('pages::settings.profile')
            ->set('avatar', UploadedFile::fake()->createWithContent('foto.jpg', 'not an image'))
            ->call('saveAvatar')->assertHasErrors('avatar');

        $this->assertNull($member->fresh()->avatar_hash);
    }

    public function test_photos_are_seen_by_their_owner_and_administrators_only(): void
    {
        Storage::fake('local');
        $member = $this->person('member');
        $this->actingAs($member);
        Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('foto.jpg', 300, 300))->call('saveAvatar');
        $url = (string) $member->fresh()->avatarUrl();

        $this->actingAs($this->person('member'))->get($url)->assertForbidden();
        $this->actingAs($this->person('admin'))->get($url)->assertOk();
    }

    public function test_administrator_can_take_a_photo_down_but_not_replace_it(): void
    {
        Storage::fake('local');
        $member = $this->person('member');
        $this->actingAs($member);
        Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('foto.jpg', 300, 300))->call('saveAvatar');

        $admin = $this->person('admin');
        $this->actAs($admin);
        $this->assertFalse($admin->can('changeAvatar', $member->fresh()));
        $this->page($member)->call('removeAvatar')->assertHasNoErrors();

        $this->assertNull($member->fresh()->avatar_hash);
        Storage::disk('local')->assertDirectoryEmpty('avatars/'.$member->id);
        $this->assertSame('user.avatar_removed', AdminAuditEvent::latest('id')->value('action'));
    }

    public function test_deleting_a_person_removes_their_photo(): void
    {
        Storage::fake('local');
        $member = $this->person('member');
        $this->actingAs($member);
        Livewire::test('pages::settings.profile')->set('avatar', UploadedFile::fake()->image('foto.jpg', 300, 300))->call('saveAvatar');

        $member->fresh()->delete();

        $this->assertSame([], Storage::disk('local')->allFiles('avatars/'.$member->id));
    }
}
