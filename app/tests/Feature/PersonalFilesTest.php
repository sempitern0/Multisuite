<?php

namespace Tests\Feature;

use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
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

    public function test_same_name_gets_a_numbered_copy_and_rename_conflicts_are_refused(): void
    {
        $this->upload($this->owner, 'acta.txt', 'uno')->assertOk();
        $this->upload($this->owner, 'acta.txt', 'dos')->assertOk();
        $this->assertSame('dos', file_get_contents($this->root.'/.multisuite-private/'.$this->entry('acta (2).txt')->upload_id.'.blob'));
        $second = $this->entry('acta (2).txt');
        $this->actingAs($this->owner)->from('/files/'.$second->id)->patch('/files/'.$second->id, ['name' => 'ACTA.txt'])->assertSessionHasErrors('file_name');
        $this->patch('/files/'.$second->id, ['name' => 'acta firmada.txt'])->assertRedirect(route('files.show', $second));
        $this->assertSame('acta firmada.txt', $second->fresh()?->name);
    }

    public function test_markdown_preview_is_sanitised(): void
    {
        $markdown = "# Hola\n\n<script>alert(1)</script>\n\n[pulsa](javascript:alert(2)) y **negrita**\n";
        $this->upload($this->owner, 'nota.md', $markdown)->assertOk();
        $page = $this->actingAs($this->owner)->get('/files/'.$this->entry('nota.md')->id)->assertOk();
        $page->assertSee('<h1>Hola</h1>', false)->assertSee('<strong>negrita</strong>', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('javascript:alert', false);
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
}
