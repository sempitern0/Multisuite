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
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\TestCase;

class PersonalFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/multisuite-files-'.Str::uuid();
        mkdir($this->root, 0700);
        mkdir($this->root.'/.multisuite-private', 0700);
        file_put_contents($this->root.'/.multisuite-volume-id', 'private');
        config(['nas.volumes' => json_encode([['id' => 'private', 'group' => 'disk', 'path' => $this->root, 'reserve_bytes' => 0]]),
            'nas.uploads_enabled' => true, 'nas.upload_location' => 'private', 'nas.upload_max_bytes' => 100000]);
        $this->owner = User::factory()->create();
        app(StorageReservations::class)->setPoolLimit('disk', 1000000);
        app(StorageReservations::class)->setUserQuota($this->owner, 500000);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root.'/.multisuite-private/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root.'/.multisuite-private');
        unlink($this->root.'/.multisuite-volume-id');
        rmdir($this->root);
        parent::tearDown();
    }

    /** Full browser flow: register + reserve, then raw PUT. */
    private function upload(User $user, string $name, string $body, ?int $folder = null): TestResponse
    {
        $key = (string) Str::uuid();
        $this->actingAs($user)->postJson('/files', ['key' => $key, 'bytes' => strlen($body), 'name' => $name, 'folder' => $folder])->assertCreated();

        return $this->call('PUT', '/uploads/'.$key, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    private function entry(string $name): UserFile
    {
        return UserFile::where('name', $name)->firstOrFail();
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
        $this->assertSame("Pan\nLeche\n", file_get_contents($download->baseResponse->getFile()->getPathname()));
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
        $this->patch('/files/'.$file->id, ['name' => 'robado.txt'])->assertNotFound();
        $this->get('/files/folder/'.$folder->id)->assertNotFound();
        $this->delete('/files/folders/'.$folder->id)->assertNotFound();
        $this->postJson('/files', ['key' => (string) Str::uuid(), 'bytes' => 5, 'name' => 'x.txt', 'folder' => $folder->id])->assertNotFound();
        $this->assertSame('privado.txt', $file->fresh()?->name);
    }

    public function test_folders_are_unique_per_level_nested_and_only_deleted_when_empty(): void
    {
        $this->actingAs($this->owner)->post('/files/folders', ['name' => 'Facturas'])->assertRedirect();
        $folder = Folder::where('name', 'Facturas')->firstOrFail();
        $this->from('/files')->post('/files/folders', ['name' => 'facturas'])->assertSessionHasErrors('folder');
        $this->post('/files/folders', ['name' => '2026', 'parent' => $folder->id])->assertRedirect();
        $this->from('/files/folder/'.$folder->id)->delete('/files/folders/'.$folder->id)->assertSessionHasErrors('folder_delete');

        $this->upload($this->owner, 'luz.txt', 'kWh', $folder->id)->assertOk();
        $this->actingAs($this->owner)->get('/files/folder/'.$folder->id)->assertSee('luz.txt')->assertSee('2026');
        $this->get('/files')->assertDontSee('luz.txt');

        $child = Folder::where('name', '2026')->firstOrFail();
        $this->patch('/files/folders/'.$child->id, ['name' => '2026 (pagadas)'])->assertRedirect();
        $this->delete('/files/folders/'.$child->id)->assertRedirect(route('files.folder', $folder));
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
        $this->assertSame('uno', file_get_contents($this->get('/files/'.$old->id.'/content?download=1')->baseResponse->getFile()->getPathname()));

        // Restoring the old version swaps them and keeps the visible name.
        $this->post('/files/'.$old->id.'/promote')->assertRedirect(route('files.show', $old));
        $this->assertTrue($old->fresh()?->isCurrent());
        $this->assertSame('Acta.txt', $old->fresh()?->name);
        $this->assertSame($old->id, $current->fresh()?->version_of_id);

        // Purging a version deletes its bytes and returns quota; current files cannot be purged directly.
        $this->delete('/files/'.$current->id.'/purge')->assertRedirect(route('files.show', $old));
        $this->assertFileDoesNotExist($this->blob($current));
        $this->assertSame(3, $this->used($this->owner));
        $this->from('/files/'.$old->id)->delete('/files/'.$old->id.'/purge')->assertSessionHasErrors('file_purge');
        $this->assertFileExists($this->blob($old));
    }

    public function test_trash_hides_restores_and_purge_returns_quota(): void
    {
        $folder = app(PersonalFiles::class)->createFolder($this->owner, null, 'Facturas');
        $this->upload($this->owner, 'luz.txt', 'kWh 120', $folder->id)->assertOk();
        $this->upload($this->owner, 'luz.txt', 'kWh 125', $folder->id)->assertOk();   // + one version
        $file = UserFile::whereNull('version_of_id')->sole();
        $version = UserFile::whereNotNull('version_of_id')->sole();

        $this->actingAs($this->owner)->delete('/files/'.$file->id)->assertRedirect(route('files.folder', $folder));
        // The flash message names the file, so check the listing links instead of the text.
        $this->get('/files/folder/'.$folder->id)->assertDontSee('href="'.route('files.show', $file).'"', false)
            ->assertSee('En esta carpeta: 0 elementos · 0 B');
        $this->get('/files/trash')->assertSee('luz.txt')->assertSee('14 B');
        $this->assertSame(14, $this->used($this->owner));
        $this->delete('/files/folders/'.$folder->id)->assertRedirect();             // folder now empty
        $this->assertModelMissing($folder);

        // Original folder gone: restore to the root; a clashing name gets a numbered copy.
        $this->upload($this->owner, 'luz.txt', 'otra')->assertOk();
        $this->post('/files/'.$file->id.'/restore')->assertRedirect(route('files.show', $file));
        $this->assertSame(['folder_id' => null, 'name' => 'luz (2).txt'], $file->fresh()?->only(['folder_id', 'name']));
        $this->assertNull($file->fresh()?->trashed_at);

        // Purge from the trash removes the file and its versions, and frees every byte.
        $this->delete('/files/'.$file->id)->assertRedirect();
        $this->delete('/files/'.$file->id.'/purge')->assertRedirect(route('files.trash'));
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

        $this->actingAs($intruder)->delete('/files/'.$current->id)->assertNotFound();
        $this->post('/files/'.$current->id.'/restore')->assertNotFound();
        $this->delete('/files/'.$current->id.'/purge')->assertNotFound();
        $this->post('/files/'.$old->id.'/promote')->assertNotFound();
        $this->get('/files/trash')->assertDontSee('mio.txt');
        $this->delete('/files/trash')->assertRedirect();
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
            $this->actingAs($this->owner)->from('/files/trash')->delete('/files/'.$file->id.'/purge')->assertSessionHasErrors('file_purge');
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
        $this->assertSame(['v3', 'v2'], $versions->map(fn ($v) => file_get_contents($this->blob($v)))->all());
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

        $this->actingAs($this->owner)->get('/files')->assertSee('En esta carpeta: 2 elementos · 15 B')->assertSee('2 elementos · 10 B');
        $this->get('/files/folder/'.$year->id)->assertSee('En esta carpeta: 2 elementos · 7 B')->assertSee('0 elementos · 0 B');
    }
}
