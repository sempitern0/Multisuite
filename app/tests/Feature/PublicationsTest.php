<?php

namespace Tests\Feature;

use App\Actions\Users\DeleteUser;
use App\Enums\PublicationState;
use App\Models\Folder;
use App\Models\PrivateUpload;
use App\Models\Publication;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\MediaLibrary;
use App\Services\Files\PersonalFiles;
use App\Services\Files\Publications;
use App\Services\NavidromeAccounts;
use App\Services\Storage\StorageReservations;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * Publication for everyone (S04b): the contributor proposes, only an administrator publishes,
 * withdraws or deletes; the bytes move to the common fund; the service grants the common library
 * before it is confirmed; published content survives the contributor's account.
 */
class PublicationsTest extends TestCase
{
    use RefreshDatabase, UsesPrivateStorage;

    /** @var list<string> accounts granted the common library */
    public static array $granted = [];

    public static bool $failGrant = false;

    private User $member;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        config(['portal.services.music.enabled' => true, 'provisioning.music.enabled' => true,
            'provisioning.music.url' => 'http://music.test', 'provisioning.music.username' => 'operator', 'provisioning.music.password' => 'secret']);
        Http::preventStrayRequests();
        self::$granted = [];
        self::$failGrant = false;
        $this->app->instance(NavidromeAccounts::class, new class extends NavidromeAccounts
        {
            public function ensurePersonalLibrary(ServiceAccount $account, ?string $section, string $label): void {}

            public function ensureCommonLibrary(ServiceAccount $account, ?string $section, string $label): void
            {
                if (PublicationsTest::$failGrant) {
                    throw new RuntimeException('unavailable');
                }
                PublicationsTest::$granted[] = $account->remote_id;
            }

            public function remove(ServiceAccount $account): void {}
        });
        $this->owner->role = 'owner';
        $this->owner->save();
        foreach (['member', 'other'] as $name) {
            $user = User::factory()->create(['role' => 'member', 'service_access' => ['music']]);
            app(StorageReservations::class)->setUserQuota($user, 500000);
            $this->account($user);
            $this->{$name} = $user;
        }
        $this->withoutDefer();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root.'/.multisuite-media');
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    public function test_an_administrator_publishes_and_withdraws_and_the_space_moves_with_it(): void
    {
        $id = $this->song($this->member, 'canción.mp3', 'ID3'.str_repeat('a', 1000));
        $publications = app(Publications::class);

        try {
            $publications->request($this->other, $id);
            $this->fail('Someone else cannot propose my file.');
        } catch (ValidationException) {
        }
        $publication = $publications->request($this->member, $id);
        $this->assertSame(PublicationState::Requested->value, $publication->state);
        $this->assertSame($publication->id, $publications->request($this->member, $id)->id, 'Asking twice is the same proposal.');
        $this->assertThrows(fn () => app(PersonalFiles::class)->trash($this->member, $id), RuntimeException::class);

        $this->assertThrows(fn () => $publications->publish($this->other, $publication->id), AuthorizationException::class);
        $this->actingAs($this->other)->get(route('publications.index'))->assertForbidden();

        $used = UserStorageQuota::findOrFail($this->member->id)->used_bytes;
        $publications->publish($this->owner, $publication->id);
        $this->assertSame(PublicationState::Published->value, $publication->fresh()?->state);
        $this->assertSame($used - 1003, UserStorageQuota::findOrFail($this->member->id)->used_bytes);
        $this->assertSame(1003, app(StorageReservations::class)->commonBytes());
        $this->assertEqualsCanonicalizing([$this->remote($this->member), $this->remote($this->other)], self::$granted);

        $blob = $this->root.'/.multisuite-private/'.PrivateUpload::findOrFail($publication->upload_id)->id.'.blob';
        $common = $this->root.'/.multisuite-media/music/comun/canción.mp3';
        $this->assertSame(fileinode($blob), fileinode($common));
        app(MediaLibrary::class)->sync($this->member);
        $this->assertFileDoesNotExist($this->root.'/.multisuite-media/music/'.$this->remote($this->member).'/canción.mp3', 'Shown once, in the common library.');
        $this->assertThrows(fn () => app(PersonalFiles::class)->renameFile($this->member, $id, 'otra.mp3'), RuntimeException::class);

        $publications->askWithdrawal($this->member, $publication->id);
        $this->assertNotNull($publication->fresh()?->withdrawal_requested_at);
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => time()])->get(route('publications.index'))->assertOk()->assertSee('canción.mp3')->assertSee('Pide retirarlo');

        $publications->withdraw($this->owner, $publication->id);
        $this->assertNull($publication->fresh());
        $this->assertSame($used, UserStorageQuota::findOrFail($this->member->id)->used_bytes);
        $this->assertSame(0, app(StorageReservations::class)->commonBytes());
        $this->assertFileDoesNotExist($common);
        app(PersonalFiles::class)->trash($this->member, $id); // theirs again
    }

    public function test_nothing_is_published_until_the_service_grants_it(): void
    {
        $id = $this->song($this->member, 'tema.mp3', 'ID3'.str_repeat('b', 500));
        $publication = app(Publications::class)->request($this->member, $id);
        $used = UserStorageQuota::findOrFail($this->member->id)->used_bytes;
        self::$failGrant = true;

        $this->assertThrows(fn () => app(Publications::class)->publish($this->owner, $publication->id), ValidationException::class);

        $this->assertSame(PublicationState::Requested->value, $publication->fresh()?->state);
        $this->assertSame($used, UserStorageQuota::findOrFail($this->member->id)->used_bytes);
        $this->assertSame(0, app(StorageReservations::class)->commonBytes());
        $this->assertFileDoesNotExist($this->root.'/.multisuite-media/music/comun/tema.mp3');

        app(Publications::class)->reject($this->owner, $publication->id);
        $this->assertNull($publication->fresh());
    }

    public function test_published_content_stays_as_common_property_when_its_contributor_leaves(): void
    {
        $id = $this->song($this->member, 'himno.mp3', 'ID3'.str_repeat('c', 700));
        $publication = app(Publications::class)->request($this->member, $id);
        app(Publications::class)->publish($this->owner, $publication->id);
        $upload = $publication->upload_id;

        app(DeleteUser::class)->handle($this->owner, $this->member, $this->member->email);

        $publication = $publication->fresh();
        $this->assertNotNull($publication);
        $this->assertSame([null, null], [$publication->contributor_id, $publication->user_file_id]);
        $this->assertFileExists($this->root.'/.multisuite-media/music/comun/himno.mp3');
        $this->assertThrows(fn () => app(Publications::class)->withdraw($this->owner, $publication->id), ValidationException::class);

        app(Publications::class)->destroy($this->owner, $publication->id);
        $this->assertNull($publication->fresh());
        $this->assertFileDoesNotExist($this->root.'/.multisuite-private/'.$upload.'.blob');
        $this->assertFileDoesNotExist($this->root.'/.multisuite-media/music/comun/himno.mp3');
        $this->assertSame(0, app(StorageReservations::class)->commonBytes());
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'content.deleted', 'actor_id' => $this->owner->id]);
    }

    private function song(User $user, string $name, string $body): int
    {
        app(ConnectedFolders::class)->ensure($user);
        $music = Folder::where('user_id', $user->id)->where('media_link', 'music')->firstOrFail();
        $this->upload($user, $name, $body, $music->id)->assertOk();

        return $this->entry($name)->id;
    }

    private function account(User $user): void
    {
        $account = new ServiceAccount;
        $account->user_id = $user->id;
        $account->service = 'music';
        $account->remote_id = (string) Str::uuid();
        $account->username = $user->email;
        $account->status = 'ready';
        $account->save();
    }

    private function remote(User $user): string
    {
        return (string) ServiceAccount::where('user_id', $user->id)->value('remote_id');
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                is_dir($path.'/'.$name) && ! is_link($path.'/'.$name) ? $this->removeTree($path.'/'.$name) : unlink($path.'/'.$name);
            }
        }
        rmdir($path);
    }
}
