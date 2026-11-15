<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\PrivateUpload;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\MediaLibrary;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesFilePages;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

/**
 * Connected folders (S04a): what a person uploads to «Música», «Libros» or «Cómics» is stored
 * once, unencrypted, and mirrored by hard link into the directory their service reads, only for
 * them. The mirror follows every change and converges again after any failure.
 */
class ConnectedFoldersTest extends TestCase
{
    use RefreshDatabase, UsesFilePages, UsesPrivateStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
        foreach (['music', 'reading'] as $service) {
            config([
                'portal.services.'.$service.'.enabled' => true,
                'provisioning.'.$service.'.enabled' => true,
                'provisioning.'.$service.'.url' => 'http://'.$service.'.test',
                'provisioning.'.$service.'.username' => 'operator',
                'provisioning.'.$service.'.password' => 'secret',
            ]);
        }
        // Accounts below stay "pending": no service call is expected unless a test fakes one.
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root.'/.multisuite-media');
        $this->tearDownPrivateStorage();
        parent::tearDown();
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

    private function account(User $user, string $service): ServiceAccount
    {
        $account = new ServiceAccount;
        $account->user_id = $user->id;
        $account->service = $service;
        $account->remote_id = (string) Str::uuid();
        $account->username = $user->email;
        $account->status = 'pending';
        $account->save();

        return $account;
    }

    private function connected(User $user, string $connection): Folder
    {
        return Folder::where('user_id', $user->id)->where('connection', $connection)->firstOrFail();
    }

    private function media(ServiceAccount $account, ?string $section): string
    {
        return $this->root.'/.multisuite-media/'.$account->service.'/'.strtolower($account->remote_id).($section === null ? '' : '/'.$section);
    }

    public function test_each_person_gets_the_folders_of_their_services_once_without_taking_over_their_own(): void
    {
        $this->actingAs($this->owner);
        app(PersonalFiles::class)->createFolder($this->owner, null, 'Libros');

        $this->get('/files')->assertOk()->assertSee('Música')->assertSee('Libros (conectada)')->assertSee('Cómics')->assertSee('Conectada con Lectura');
        app(ConnectedFolders::class)->ensure($this->owner);

        $this->assertSame(['books', 'comics', 'music'], Folder::where('user_id', $this->owner->id)->whereNotNull('connection')->orderBy('connection')->pluck('connection')->all());
        $this->assertSame(0, Folder::where('name', 'Libros')->whereNotNull('connection')->count());

        $member = User::factory()->create();
        $member->service_access = ['music'];
        $member->save();
        app(ConnectedFolders::class)->ensure($member);
        $this->assertSame(['music'], Folder::where('user_id', $member->id)->whereNotNull('connection')->pluck('connection')->all());
    }

    public function test_connected_folders_cannot_be_renamed_or_deleted(): void
    {
        app(ConnectedFolders::class)->ensure($this->owner);
        $books = $this->connected($this->owner, 'books');

        $this->expectException(RuntimeException::class);
        try {
            app(PersonalFiles::class)->renameFolder($this->owner, $books->id, 'Otra cosa');
        } finally {
            $this->assertSame('Libros', $books->fresh()?->name);
            try {
                app(PersonalFiles::class)->deleteEmptyFolder($this->owner, $books->id);
            } catch (RuntimeException) {
                $this->assertNotNull($books->fresh());
            }
        }
    }

    public function test_an_upload_to_a_connected_folder_is_stored_once_unencrypted_and_mirrored_for_its_service(): void
    {
        $account = $this->account($this->owner, 'reading');
        app(ConnectedFolders::class)->ensure($this->owner);
        $books = $this->connected($this->owner, 'books');
        $saga = app(PersonalFiles::class)->createFolder($this->owner, $books->id, 'Saga');
        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        $this->withoutDefer();
        $this->upload($this->owner, 'tomo 1.pdf', $pdf, $saga->id)->assertOk();

        $entry = $this->entry('tomo 1.pdf');
        $upload = PrivateUpload::findOrFail($entry->upload_id);
        $this->assertSame(['books', null], [$upload->connection, $upload->cipher]);
        $blob = $this->root.'/.multisuite-private/'.$upload->id.'.blob';
        $this->assertSame($pdf, file_get_contents($blob));
        $this->assertSame(0644, fileperms($blob) & 0777);

        // Mirrored by the upload itself (no manual sync): same inode, so a single copy on disk.
        $linked = $this->media($account, 'libros').'/Saga/tomo 1.pdf';
        $this->assertFileExists($linked);
        $this->assertSame(fileinode($blob), fileinode($linked));
        $this->assertSame(strlen($pdf), UserStorageQuota::findOrFail($this->owner->id)->used_bytes);

        // Rename, trash, restore and permanent deletion all reach the mirror.
        $this->actingAs($this->owner);
        $this->renameFile($entry->id, 'tomo uno.pdf');
        app(MediaLibrary::class)->sync($this->owner);
        $this->assertFileDoesNotExist($linked);
        $this->assertFileExists($this->media($account, 'libros').'/Saga/tomo uno.pdf');

        app(PersonalFiles::class)->trash($this->owner, $entry->id);
        app(MediaLibrary::class)->sync($this->owner);
        $this->assertSame([], glob($this->media($account, 'libros').'/*'));

        app(PersonalFiles::class)->restore($this->owner, $entry->id);
        app(MediaLibrary::class)->sync($this->owner);
        $this->assertFileExists($this->media($account, 'libros').'/Saga/tomo uno.pdf');

        app(PersonalFiles::class)->trash($this->owner, $entry->id);
        app(PersonalFiles::class)->purge($this->owner, $entry->id);
        app(MediaLibrary::class)->sync($this->owner);
        $this->assertFileDoesNotExist($blob);
        $this->assertSame([], glob($this->media($account, 'libros').'/*'));
        $this->assertSame(0, UserStorageQuota::findOrFail($this->owner->id)->used_bytes);
    }

    public function test_ordinary_folders_stay_encrypted_and_out_of_the_services(): void
    {
        $account = $this->account($this->owner, 'reading');
        $this->upload($this->owner, 'privado.pdf', "%PDF-1.4\n%%EOF\n")->assertOk();
        app(MediaLibrary::class)->sync($this->owner);

        $this->assertNull(PrivateUpload::findOrFail($this->entry('privado.pdf')->upload_id)->connection);
        $this->assertNotNull(PrivateUpload::findOrFail($this->entry('privado.pdf')->upload_id)->cipher);
        $this->assertSame([], glob($this->media($account, 'libros').'/*'));
    }

    public function test_each_person_only_reaches_their_own_mirror_and_losing_access_empties_it(): void
    {
        $other = User::factory()->create();
        app(StorageReservations::class)->setUserQuota($other, 500000);
        $mine = $this->account($this->owner, 'music');
        $theirs = $this->account($other, 'music');
        app(ConnectedFolders::class)->ensure($this->owner);
        app(ConnectedFolders::class)->ensure($other);
        $song = "ID3\x03\x00\x00\x00\x00\x00\x0a".str_repeat("\x00", 10)."\xff\xfb\x90\x64".str_repeat("\x00", 24);
        $this->upload($this->owner, 'mia.mp3', $song, $this->connected($this->owner, 'music')->id)->assertOk();
        $this->upload($other, 'suya.mp3', $song, $this->connected($other, 'music')->id)->assertOk();
        app(MediaLibrary::class)->sync($this->owner);
        app(MediaLibrary::class)->sync($other);

        $this->assertSame(['mia.mp3'], array_map('basename', glob($this->media($mine, null).'/*') ?: []));
        $this->assertSame(['suya.mp3'], array_map('basename', glob($this->media($theirs, null).'/*') ?: []));

        $other->service_access = ['reading'];
        $other->save();
        app(MediaLibrary::class)->sync($other);
        $this->assertSame([], glob($this->media($theirs, null).'/*'));
        $this->assertNotNull($this->entry('suya.mp3'), 'The file stays in Mis archivos.');
    }

    public function test_only_the_comics_folder_accepts_comic_archives(): void
    {
        $this->account($this->owner, 'reading');
        app(ConnectedFolders::class)->ensure($this->owner);
        $zip = "PK\x03\x04".str_repeat("\x00", 26)."PK\x05\x06".str_repeat("\x00", 18);

        $this->upload($this->owner, 'numero 1.cbz', $zip)->assertUnprocessable();
        $this->upload($this->owner, 'numero 1.cbz', $zip, $this->connected($this->owner, 'comics')->id)->assertOk();
    }

    public function test_a_missing_or_stray_entry_is_repaired_by_the_next_pass(): void
    {
        $account = $this->account($this->owner, 'reading');
        app(ConnectedFolders::class)->ensure($this->owner);
        $this->upload($this->owner, 'novela.pdf', "%PDF-1.4\n%%EOF\n", $this->connected($this->owner, 'books')->id)->assertOk();
        app(MediaLibrary::class)->sync($this->owner);
        $linked = $this->media($account, 'libros').'/novela.pdf';
        unlink($linked);
        file_put_contents($this->media($account, 'libros').'/intruso.pdf', 'x');

        $this->artisan('portal:media-sync')->assertSuccessful();

        $this->assertFileExists($linked);
        $this->assertFileDoesNotExist($this->media($account, 'libros').'/intruso.pdf');
    }
}
