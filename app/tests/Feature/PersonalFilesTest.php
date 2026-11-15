<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\StoragePool;
use App\Models\User;
use App\Models\UserFile;
use App\Models\UserStorageQuota;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\ReadsEncryptedFiles;
use Tests\Concerns\UsesFilePages;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

class PersonalFilesTest extends TestCase
{
    use ReadsEncryptedFiles, RefreshDatabase;
    use UsesFilePages;
    use UsesPrivateStorage;

    /** 2×2 grey JPEG. */
    private const JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDABsSFBcUERsXFhceHBsgKEIrKCUlKFE6PTBCYFVlZF9VXVtqeJmBanGQc1tdhbWGkJ6jq62rZ4C8ybqmx5moq6T/wAALCAACAAIBAREA/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oACAEBAAA/ACv/2Q==';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPrivateStorage();
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateStorage();
        parent::tearDown();
    }

    public function test_owner_uploads_lists_previews_and_downloads_a_file(): void
    {
        $this->upload($this->owner, 'lista de la compra.txt', "Pan\nLeche\n")->assertOk()->assertJsonPath('state', 'ready');
        $file = $this->entry('lista de la compra.txt');

        $this->get('/files')->assertOk()->assertSee('lista de la compra.txt')->assertSee('Subir archivos');
        $this->get('/files/'.$file->id)->assertOk()->assertSee('Pan')->assertSee('Leche');

        $inline = $this->get('/files/'.$file->id.'/content')->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $inline->headers->get('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $inline->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $inline->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('inline', (string) $inline->headers->get('Content-Disposition'));

        $download = $this->get('/files/'.$file->id.'/content?download=1')->assertOk();
        $this->assertSame('application/octet-stream', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame("Pan\nLeche\n", $download->streamedContent());
    }

    public function test_other_people_cannot_see_or_touch_files_or_folders(): void
    {
        $this->upload($this->owner, 'privado.txt', 'secreto');
        $file = $this->entry('privado.txt');
        $folder = app(PersonalFiles::class)->createFolder($this->owner, null, 'Médico');
        $intruder = User::factory()->create();
        $intruder->role = 'owner';
        $intruder->save();

        $this->actingAs($intruder)->get('/files')->assertOk()->assertDontSee('privado.txt')->assertDontSee('Médico');
        $this->get('/files/'.$file->id)->assertNotFound();
        $this->get('/files/'.$file->id.'/content')->assertNotFound();
        $this->renameFile($file->id, 'robado.txt')->assertNotFound();
        $this->get('/files/folder/'.$folder->id)->assertNotFound();
        $this->deleteFolder($folder->id)->assertNotFound();
        $this->postJson('/files', ['key' => (string) Str::uuid(), 'bytes' => 5, 'name' => 'x.txt', 'folder' => $folder->id])->assertNotFound();
        $this->assertSame('privado.txt', $file->fresh()?->name);
    }

    public function test_folders_are_unique_per_level_nested_and_only_deleted_when_empty(): void
    {
        $this->actingAs($this->owner)->createFolder('Facturas')->assertHasNoErrors();
        $folder = Folder::where('name', 'Facturas')->firstOrFail();
        $this->from('/files')->createFolder('facturas')->assertHasErrors('newFolder');
        $this->createFolder('2026', $folder->id)->assertHasNoErrors();
        $this->from('/files/folder/'.$folder->id)->deleteFolder($folder->id)->assertHasErrors('folder_delete');

        $this->upload($this->owner, 'luz.txt', 'kWh', $folder->id)->assertOk();
        $this->actingAs($this->owner)->get('/files/folder/'.$folder->id)->assertSee('luz.txt')->assertSee('2026');
        $this->get('/files')->assertDontSee('luz.txt');

        $child = Folder::where('name', '2026')->firstOrFail();
        $this->renameFolder($child->id, '2026 (pagadas)')->assertHasNoErrors();
        $this->deleteFolder($child->id)->assertRedirect(route('files.folder', $folder));
        $this->assertModelMissing($child);
    }

    public function test_depth_limit_and_unsafe_names_are_rejected(): void
    {
        $service = app(PersonalFiles::class);
        $parent = null;
        for ($i = 1; $i <= PersonalFiles::MAX_DEPTH; $i++) {
            $parent = $service->createFolder($this->owner, $parent?->id, 'Nivel '.$i);
        }
        try {
            $service->createFolder($this->owner, $parent?->id, 'Demasiado');
            $this->fail('Depth limit not enforced.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame('a b', PersonalFiles::cleanName("a/\\\0b", 50));
        $this->assertSame('fichero', PersonalFiles::cleanName('../fichero', 50));
        foreach (['..', '   ', '/'] as $bad) {
            try {
                PersonalFiles::cleanName($bad, 50);
                $this->fail('Accepted '.$bad);
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    private function blob(UserFile $file): string
    {
        return $this->root.'/.multisuite-private/'.$file->upload_id.'.blob';
    }

    private function plain(UserFile $file): string
    {
        return $this->decrypted($this->blob($file), 'file:'.$file->upload_id);
    }

    private function used(User $user): int
    {
        return (int) UserStorageQuota::findOrFail($user->id)->used_bytes;
    }

    public function test_same_name_creates_versions_that_can_be_restored_and_purged(): void
    {
        $this->upload($this->owner, 'acta.txt', 'uno')->assertOk();
        $this->upload($this->owner, 'Acta.txt', 'dos!')->assertOk();
        $current = UserFile::whereNull('version_of_id')->whereNull('trashed_at')->sole();
        $old = UserFile::whereNotNull('version_of_id')->sole();
        $this->assertSame('Acta.txt', $current->name);
        $this->assertSame($current->id, $old->version_of_id);
        $this->assertNull($old->folder_id);
        $this->assertSame(7, $this->used($this->owner));                 // versions keep counting

        $this->actingAs($this->owner)->get('/files')->assertSee('Acta.txt')->assertSee('1 elemento · 4 B');
        $this->get('/files/'.$current->id)->assertSee('Versiones anteriores');
        $this->assertSame('uno', $this->get('/files/'.$old->id.'/content?download=1')->streamedContent());

        // Restoring the old version swaps them and keeps the visible name.
        $this->promoteFile($old->id)->assertHasNoErrors();
        $this->assertTrue($old->fresh()?->isCurrent());
        $this->assertSame('Acta.txt', $old->fresh()?->name);
        $this->assertSame($old->id, $current->fresh()?->version_of_id);

        // Purging a version deletes its bytes and returns quota; current files cannot be purged directly.
        $this->purgeFile($current->id)->assertRedirect(route('files.show', $old));
        $this->assertFileDoesNotExist($this->blob($current));
        $this->assertSame(3, $this->used($this->owner));
        $this->from('/files/'.$old->id)->purgeFile($old->id)->assertHasErrors('file_purge');
        $this->assertFileExists($this->blob($old));
    }

    public function test_trash_hides_restores_and_purge_returns_quota(): void
    {
        $folder = app(PersonalFiles::class)->createFolder($this->owner, null, 'Facturas');
        $this->upload($this->owner, 'luz.txt', 'kWh 120', $folder->id)->assertOk();
        $this->upload($this->owner, 'luz.txt', 'kWh 125', $folder->id)->assertOk();   // + one version
        $file = UserFile::whereNull('version_of_id')->sole();
        $version = UserFile::whereNotNull('version_of_id')->sole();

        $this->actingAs($this->owner)->trashFile($file->id)->assertRedirect(route('files.folder', $folder));
        // The flash message names the file, so check the listing links instead of the text.
        $this->get('/files/folder/'.$folder->id)->assertDontSee('href="'.route('files.show', $file).'"', false)
            ->assertSee('0 elementos · 0 B');
        $this->get('/files/trash')->assertSee('luz.txt')->assertSee('14 B');
        $this->assertSame(14, $this->used($this->owner));
        $this->deleteFolder($folder->id)->assertRedirect();             // folder now empty
        $this->assertModelMissing($folder);

        // Original folder gone: restore to the root; a clashing name gets a numbered copy.
        $this->upload($this->owner, 'luz.txt', 'otra')->assertOk();
        $this->restoreFile($file->id)->assertHasNoErrors();
        $this->assertSame(['folder_id' => null, 'name' => 'luz (2).txt'], $file->fresh()?->only(['folder_id', 'name']));
        $this->assertNull($file->fresh()?->trashed_at);

        // Purge from the trash removes the file and its versions, and frees every byte.
        $this->trashFile($file->id)->assertRedirect();
        $this->purgeFile($file->id)->assertRedirect(route('files.trash'));
        $this->assertFileDoesNotExist($this->blob($file));
        $this->assertFileDoesNotExist($this->blob($version));
        $this->assertModelMissing($file);
        $this->assertModelMissing($version);
        $this->assertSame(4, $this->used($this->owner));
        $this->assertSame(4, StoragePool::findOrFail('disk')->used_bytes);
        $this->assertDatabaseHas('private_uploads', ['id' => $file->upload_id, 'state' => 'purged']);
    }

    public function test_other_people_cannot_trash_restore_purge_or_promote(): void
    {
        // Bodies of a few bytes: fileinfo cannot type a single byte, which is (rightly) refused.
        $this->upload($this->owner, 'mio.txt', 'contenido A')->assertOk();
        $this->upload($this->owner, 'mio.txt', 'contenido B')->assertOk();
        $current = UserFile::whereNull('version_of_id')->sole();
        $old = UserFile::whereNotNull('version_of_id')->sole();
        app(PersonalFiles::class)->trash($this->owner, $current->id);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->trashFile($current->id)->assertNotFound();
        $this->restoreFile($current->id)->assertNotFound();
        $this->purgeFile($current->id)->assertNotFound();
        $this->promoteFile($old->id)->assertNotFound();
        $this->get('/files/trash')->assertDontSee('mio.txt');
        $this->emptyTrash()->assertHasNoErrors();
        $this->assertFileExists($this->blob($current));
        $this->assertFileExists($this->blob($old));
    }

    public function test_purge_fails_closed_when_the_volume_is_unavailable(): void
    {
        $this->upload($this->owner, 'seguro.txt', 'datos')->assertOk();
        $file = $this->entry('seguro.txt');
        app(PersonalFiles::class)->trash($this->owner, $file->id);
        rename($this->root.'/.multisuite-volume-id', $this->root.'/.marker-off');
        try {
            $this->actingAs($this->owner)->from('/files/trash')->purgeFile($file->id)->assertHasErrors('file_purge');
            $this->assertSame(1, app(PersonalFiles::class)->emptyTrash($this->owner));
        } finally {
            rename($this->root.'/.marker-off', $this->root.'/.multisuite-volume-id');
        }
        $this->assertFileExists($this->blob($file));
        $this->assertSame(5, $this->used($this->owner));
        $this->assertNotNull($file->fresh());
    }

    public function test_retention_purges_old_trash_and_excess_versions(): void
    {
        config(['nas.trash_days' => 30, 'nas.max_versions' => 2]);
        foreach (['v1', 'v2', 'v3', 'v4'] as $body) {
            $this->upload($this->owner, 'informe.txt', $body)->assertOk();
            $this->travel(1)->minutes();
        }
        $this->upload($this->owner, 'viejo.txt', 'zz')->assertOk();
        $this->upload($this->owner, 'reciente.txt', 'yy')->assertOk();
        app(PersonalFiles::class)->trash($this->owner, $this->entry('viejo.txt')->id);
        $this->travel(31)->days();
        app(PersonalFiles::class)->trash($this->owner, $this->entry('reciente.txt')->id);

        $this->artisan('portal:files-purge')->assertSuccessful();
        $this->assertNull(UserFile::where('name', 'viejo.txt')->first());
        $this->assertNotNull(UserFile::where('name', 'reciente.txt')->first());
        $versions = UserFile::whereNotNull('version_of_id')->orderByDesc('superseded_at')->get();
        $this->assertCount(2, $versions);                                  // v3 and v2 kept, v1 purged
        $this->assertSame(['v3', 'v2'], $versions->map(fn ($v) => $this->plain($v))->all());
        $this->assertSame(2 + 2 + 2 + 2, $this->used($this->owner));        // v4 + v3 + v2 + reciente
    }

    public function test_markdown_preview_is_sanitised(): void
    {
        // A .md containing <script> or <html> is detected as HTML and refused at upload;
        // inline HTML that passes detection must still be stripped when rendering.
        $markdown = "# Hola\n\nTexto con <span onclick=\"alert(1)\">HTML en línea</span>, [pulsa](javascript:alert(2)) y **negrita**\n";
        $this->upload($this->owner, 'nota.md', $markdown)->assertOk();
        $page = $this->actingAs($this->owner)->get('/files/'.$this->entry('nota.md')->id)->assertOk();
        $page->assertSee('<h1>Hola</h1>', false)->assertSee('<strong>negrita</strong>', false)
            ->assertSee('HTML en línea')->assertDontSee('HTML en línea</span>', false)
            ->assertDontSee('onclick', false)->assertDontSee('javascript:alert', false);
        $this->assertSame("img-src 'self' data:", $page->headers->get('Content-Security-Policy'));
    }

    public function test_explorer_searches_every_folder_filters_by_kind_sorts_and_remembers_the_view(): void
    {
        $this->actingAs($this->owner);
        $this->createFolder('Viaje 2026');
        $year = Folder::where('user_id', $this->owner->id)->where('name', 'Viaje 2026')->firstOrFail();
        $this->upload($this->owner, 'playa.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='), $year->id)->assertOk();
        $this->upload($this->owner, 'notas playa.txt', 'arena y sol')->assertOk();
        $this->upload($this->owner, 'lista.txt', 'pan')->assertOk();
        $other = User::factory()->create();
        app(StorageReservations::class)->setUserQuota($other, 500000);
        $this->upload($other, 'playa secreta.txt', 'ajeno')->assertOk();
        $this->actingAs($this->owner);

        $names = fn ($page) => $page->get('listing')['entries']->pluck('name')->all();
        $page = Livewire::test('pages::files.index');
        $this->assertSame(['lista.txt', 'notas playa.txt'], $names($page));

        // A search looks in every folder of the person, never in someone else's files.
        $page->set('search', 'PLAYA');
        $this->assertSame(['notas playa.txt', 'playa.png'], $names($page));
        $page->assertSee('Viaje 2026')->assertDontSee('playa secreta.txt');
        $page->set('search', '100%_')->assertSee('Nada coincide');

        $page->set('search', '')->set('kind', 'image');
        $this->assertSame([], $names($page));
        $page->set('search', 'a')->set('kind', 'document');
        $this->assertSame(['lista.txt', 'notas playa.txt'], $names($page));
        $page->set('sort', 'size')->call('toggleDirection');
        $this->assertSame(['notas playa.txt', 'lista.txt'], $names($page));
        $page->call('clearFilters');
        $this->assertSame(['lista.txt', 'notas playa.txt'], $names($page));

        $page->call('setView', 'gallery')->assertSee('ui-files', false)->assertSee('data-view="gallery"', false);
        $this->assertSame('gallery', $this->owner->fresh()->preferences()->filesView());
        Livewire::test('pages::files.index')->assertSee('data-view="gallery"', false);
        Livewire::test('pages::files.index')->call('setView', 'cinema')->assertStatus(422);
        // The Preferences form keeps the chosen view.
        Livewire::test('pages::settings.preferences')->set('density', 'compact');
        $this->assertSame('gallery', $this->owner->fresh()->preferences()->filesView());
    }

    public function test_selection_bar_trashes_several_files_and_back_arrow_returns_to_the_parent(): void
    {
        $this->upload($this->owner, 'a.txt', 'nota uno')->assertOk();
        $this->upload($this->owner, 'b.txt', 'nota dos')->assertOk();
        $this->upload($this->owner, 'c.txt', 'nota tres')->assertOk();
        $other = User::factory()->create();
        app(StorageReservations::class)->setUserQuota($other, 500000);
        $this->upload($other, 'ajeno.txt', 'nota ajena')->assertOk();
        $foreign = UserFile::where('user_id', $other->id)->firstOrFail();
        $this->actingAs($this->owner);
        $a = $this->entry('a.txt');
        $b = $this->entry('b.txt');

        $page = Livewire::test('pages::files.index')->assertSee('ui-selection-bar', false)->assertDontSee('Volver a');
        // Someone else's file in the selection: nothing moves (all or nothing).
        $page->set('selectedFiles', [$a->id, $foreign->id])->call('trashSelected')->assertNotFound();
        $this->assertNull($a->fresh()->trashed_at);
        $page->set('selectedFiles', [$a->id, (string) $b->id])->call('trashSelected')->assertSet('selectedFiles', []);
        $this->assertNotNull($a->fresh()->trashed_at);
        $this->assertNotNull($b->fresh()->trashed_at);
        $this->assertNull($this->entry('c.txt')->trashed_at);

        $this->createFolder('Fotos');
        $photos = Folder::where('user_id', $this->owner->id)->where('name', 'Fotos')->firstOrFail();
        $this->createFolder('2026', $photos->id);
        $year = Folder::where('user_id', $this->owner->id)->where('name', '2026')->firstOrFail();
        $this->get('/files/folder/'.$year->id)->assertSee('Volver a Fotos')->assertSee('href="'.route('files.folder', $photos).'"', false);
        $this->get('/files/folder/'.$photos->id)->assertSee('Volver a Mis archivos');
    }

    public function test_just_uploaded_files_are_marked_as_new_in_the_listing(): void
    {
        $this->upload($this->owner, 'nota.txt', 'hola')->assertOk();
        $this->upload($this->owner, 'otra.txt', 'adios')->assertOk();
        $new = $this->entry('nota.txt');

        Livewire::actingAs($this->owner)->test('pages::files.index')
            ->assertDontSee('data-fresh', false)
            ->dispatch('files-uploaded', ids: [$new->id, 'x'])
            ->assertSet('fresh', [$new->id])
            ->assertSeeHtml('data-fresh');
    }

    public function test_video_plays_inline_with_seeking_and_images_open_in_the_viewer(): void
    {
        $video = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom\x00\x00\x00\x08free";
        $this->upload($this->owner, 'clip.mp4', $video)->assertOk();
        $clip = $this->entry('clip.mp4');
        $this->get('/files/'.$clip->id)->assertOk()->assertSee('<video', false)->assertSee('data-player', false);
        $part = $this->get('/files/'.$clip->id.'/content', ['Range' => 'bytes=4-7'])->assertStatus(206);
        $this->assertSame('video/mp4', $part->headers->get('Content-Type'));
        $this->assertSame('bytes 4-7/'.strlen($video), $part->headers->get('Content-Range'));

        $song = "ID3\x03\x00\x00\x00\x00\x00\x0a".str_repeat("\x00", 10)."\xff\xfb\x90\x64".str_repeat("\x00", 24);
        $this->upload($this->owner, 'cancion.mp3', $song)->assertOk();
        $this->get('/files/'.$this->entry('cancion.mp3')->id)->assertOk()->assertSee('<audio', false)->assertSee('Reproducir cancion.mp3');

        Process::fake(fn () => Process::result(exitCode: 127));
        $this->upload($this->owner, 'foto.jpg', base64_decode(self::JPEG))->assertOk();
        $this->get('/files/'.$this->entry('foto.jpg')->id)->assertOk()->assertSee('data-viewer', false)->assertSee('Tamaño real');
    }

    public function test_jpeg_is_repacked_without_loss_and_only_the_final_size_counts(): void
    {
        $jpeg = base64_decode(self::JPEG);
        $received = $jpeg.str_repeat("\0", 4000);
        $tool = fn (string $output) => Process::fake(function (PendingProcess $process) use ($output) {
            file_put_contents($process->command[6], $output);

            return Process::result();
        });

        $tool($jpeg);
        $this->upload($this->owner, 'playa.jpg', $received)->assertOk();
        $this->assertSame($jpeg, $this->plain($this->entry('playa.jpg')));
        $this->assertSame(strlen($jpeg), UserStorageQuota::findOrFail($this->owner->id)->used_bytes);
        $this->assertSame(0, UserStorageQuota::findOrFail($this->owner->id)->reserved_bytes);
        $this->assertSame(0600, fileperms($this->blob($this->entry('playa.jpg'))) & 0777);

        // A result that is not the same picture, or no tool at all, keeps the upload untouched.
        $tool('not an image');
        $this->upload($this->owner, 'monte.jpg', $received)->assertOk();
        $this->assertSame($received, $this->plain($this->entry('monte.jpg')));
        Process::fake(fn () => Process::result(exitCode: 127));
        $this->upload($this->owner, 'rio.jpg', $received)->assertOk();
        $this->assertSame($received, $this->plain($this->entry('rio.jpg')));
        $this->assertSame([], glob($this->root.'/.multisuite-private/*.opt'));
    }

    public function test_pdf_is_previewed_inline_without_sandbox_and_html_is_refused(): void
    {
        $this->upload($this->owner, 'manual.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n")->assertOk();
        $pdf = $this->actingAs($this->owner)->get('/files/'.$this->entry('manual.pdf')->id.'/content')->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringNotContainsString('sandbox', (string) $pdf->headers->get('Content-Security-Policy'));
        $this->get('/files/'.$this->entry('manual.pdf')->id)->assertSee('<iframe', false);

        $this->upload($this->owner, 'pagina.html', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>')->assertUnprocessable();
        $this->get('/files')->assertDontSee('pagina.html');
    }

    public function test_quota_and_disabled_admission_are_reported_before_sending_bytes(): void
    {
        app(StorageReservations::class)->setUserQuota($this->owner, 10);
        $this->actingAs($this->owner)->postJson('/files', ['key' => (string) Str::uuid(), 'bytes' => 11, 'name' => 'grande.txt'])
            ->assertUnprocessable()->assertJsonValidationErrors('upload');
        config(['nas.uploads_enabled' => false]);
        $this->postJson('/files', ['key' => (string) Str::uuid(), 'bytes' => 5, 'name' => 'x.txt'])->assertUnprocessable();
        $this->get('/files')->assertOk()->assertSee('todavía no están activados')->assertDontSee('data-uploader-input', false);
        $this->assertDatabaseCount('user_files', 0);
    }

    public function test_folder_summaries_count_direct_items_and_exact_recursive_bytes(): void
    {
        $service = app(PersonalFiles::class);
        $docs = $service->createFolder($this->owner, null, 'Documentos');
        $year = $service->createFolder($this->owner, $docs->id, '2026');
        $service->createFolder($this->owner, $year->id, 'Vacía');
        $this->upload($this->owner, 'raiz.txt', '12345')->assertOk();            // 5 bytes at root
        $this->upload($this->owner, 'a.txt', 'abc', $docs->id)->assertOk();      // 3 bytes
        $this->upload($this->owner, 'b.txt', 'abcdefg', $year->id)->assertOk();  // 7 bytes
        $this->actingAs($this->owner)->postJson('/files', ['key' => (string) Str::uuid(), 'bytes' => 9, 'name' => 'pendiente.txt', 'folder' => $docs->id])->assertCreated();
        $other = User::factory()->create();
        app(StorageReservations::class)->setUserQuota($other, 1000);
        $this->upload($other, 'ajeno.txt', 'xxxxxxxxxx')->assertOk();

        $summaries = $service->summaries($this->owner);
        $this->assertSame(['items' => 2, 'bytes' => 15], $summaries[0]);           // Documentos + raiz.txt
        $this->assertSame(['items' => 2, 'bytes' => 10], $summaries[$docs->id]);   // 2026 + a.txt; pending ignored
        $this->assertSame(['items' => 2, 'bytes' => 7], $summaries[$year->id]);    // Vacía + b.txt

        $this->actingAs($this->owner)->get('/files')->assertSee('2 elementos · 15 B')->assertSee('2 elementos · 10 B');
        $this->get('/files/folder/'.$year->id)->assertSee('2 elementos · 7 B')->assertSee('0 elementos · 0 B');
    }
}
