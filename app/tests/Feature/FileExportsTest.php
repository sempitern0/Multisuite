<?php

namespace Tests\Feature;

use App\Models\FileExport;
use App\Models\Folder;
use App\Models\StoragePool;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Files\FileExports;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;
use ZipArchive;

class FileExportsTest extends TestCase
{
    use RefreshDatabase;
    use UsesPrivateStorage;

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

    /** @return array<string, string> entry => content */
    private function zipContents(string $path): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $entries[$name] = (string) $zip->getFromIndex($i);
            $this->assertSame(ZipArchive::CM_STORE, $zip->statIndex($i)['comp_method']);
        }
        $zip->close();
        ksort($entries);

        return $entries;
    }

    /** @return array{0: Folder, 1: Folder} */
    private function seedFiles(): array
    {
        $service = app(PersonalFiles::class);
        $bills = $service->createFolder($this->owner, null, 'Facturas');
        $year = $service->createFolder($this->owner, $bills->id, '2026');
        $this->upload($this->owner, 'luz.txt', 'kWh 120', $bills->id)->assertOk();
        $this->upload($this->owner, 'agua.txt', 'm3 14', $year->id)->assertOk();
        $this->upload($this->owner, 'notas.md', '# Notas raiz')->assertOk();
        $this->upload($this->owner, 'luz.txt', 'kWh 125', $bills->id)->assertOk();   // older one becomes a version
        $this->upload($this->owner, 'borrar.txt', 'en papelera')->assertOk();
        $service->trash($this->owner, $this->entry('borrar.txt')->id);

        return [$bills, $year];
    }

    public function test_folder_zip_contains_current_files_with_paths_and_is_downloadable(): void
    {
        [$bills] = $this->seedFiles();
        $usedBefore = (int) UserStorageQuota::findOrFail($this->owner->id)->used_bytes;

        $this->actingAs($this->owner)->post('/files/exports', ['scope' => 'folder', 'folder' => $bills->id])
            ->assertRedirect(route('files.exports.index'));
        $export = FileExport::sole();
        $this->assertSame('ready', $export->state);
        $this->assertSame('Facturas.zip', $export->name);
        $this->get('/files/exports')->assertSee('Facturas.zip')->assertSee('Descargar');

        $download = $this->get('/files/exports/'.$export->id.'/download')->assertOk();
        $this->assertSame('application/zip', $download->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment', (string) $download->headers->get('Content-Disposition'));
        $this->assertSame(['2026/agua.txt' => 'm3 14', 'luz.txt' => 'kWh 125'], $this->zipContents($download->baseResponse->getFile()->getPathname()));

        // Temporary space is held in the pool, never in the person's quota.
        $this->assertSame($usedBefore, (int) UserStorageQuota::findOrFail($this->owner->id)->used_bytes);
        $this->assertSame(0, (int) UserStorageQuota::findOrFail($this->owner->id)->reserved_bytes);
        $this->assertSame($export->estimated_bytes, StoragePool::findOrFail('disk')->reserved_bytes);
    }

    public function test_export_all_and_selection_with_name_clashes(): void
    {
        [$bills] = $this->seedFiles();
        $this->upload($this->owner, 'Facturas', 'archivo con el nombre de la carpeta')->assertOk();
        $exports = app(FileExports::class);

        $all = $exports->request($this->owner, 'all', null);
        $this->assertSame('Mis archivos.zip', $all->name);
        // Files of a folder first, then its subfolders; the folder yields to the file's name.
        $this->assertSame(['Facturas', 'notas.md', 'Facturas (2)/luz.txt', 'Facturas (2)/2026/agua.txt'], array_column($all->items, 'path'));

        $selection = $exports->request($this->owner, 'selection', null, [$this->entry('notas.md')->id], [$bills->id]);
        $this->assertSame(['notas.md', 'Facturas/luz.txt', 'Facturas/2026/agua.txt'], array_column($selection->items, 'path'));
        $path = $exports->downloadPath($this->owner, $selection->id);
        $this->assertSame(['Facturas/2026/agua.txt', 'Facturas/luz.txt', 'notas.md'], array_keys($this->zipContents($path)));
    }

    public function test_large_exports_are_built_by_the_scheduler_and_expire_with_verified_cleanup(): void
    {
        config(['nas.export_sync_max_bytes' => 1]);
        [$bills] = $this->seedFiles();
        $this->actingAs($this->owner)->post('/files/exports', ['scope' => 'folder', 'folder' => $bills->id]);
        $export = FileExport::sole();
        $this->assertSame('queued', $export->state);
        $this->get('/files/exports')->assertSee('En cola')->assertSee('window.location.reload', false);

        $this->artisan('portal:exports-run')->assertSuccessful();
        $export->refresh();
        $this->assertSame('ready', $export->state);
        $file = $this->root.'/.multisuite-private/'.$export->id.'.zip';
        $this->assertFileExists($file);

        $this->travel(25)->hours();
        $this->get('/files/exports/'.$export->id.'/download')->assertStatus(410);
        $this->artisan('portal:exports-run')->assertSuccessful();
        $this->assertFileDoesNotExist($file);
        $this->assertSame('cleaned', $export->refresh()->state);
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->get('/files/exports')->assertDontSee('Facturas.zip');
    }

    public function test_failed_build_removes_partial_file_releases_space_and_reports(): void
    {
        config(['nas.export_sync_max_bytes' => 1]);
        $this->upload($this->owner, 'efimero.txt', 'se borrara')->assertOk();
        $export = app(FileExports::class)->request($this->owner, 'all', null);
        $file = $this->entry('efimero.txt');
        app(PersonalFiles::class)->trash($this->owner, $file->id);
        app(PersonalFiles::class)->purge($this->owner, $file->id);     // source gone before the build

        $this->artisan('portal:exports-run')->assertFailed();
        $export->refresh();
        $this->assertSame('failed', $export->state);
        $this->assertNotNull($export->error);
        $this->assertFileDoesNotExist($this->root.'/.multisuite-private/'.$export->id.'.zip.part');
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->actingAs($this->owner)->get('/files/exports')->assertSee('No se pudo generar');
        $this->delete('/files/exports/'.$export->id)->assertRedirect(route('files.exports.index'));
        $this->assertSame('cleaned', $export->refresh()->state);
    }

    public function test_isolation_limits_and_space_checks(): void
    {
        $this->seedFiles();
        $own = app(FileExports::class)->request($this->owner, 'all', null);
        $intruder = User::factory()->create();
        app(StorageReservations::class)->setUserQuota($intruder, 1000);

        $this->actingAs($intruder)->get('/files/exports/'.$own->id.'/download')->assertNotFound();
        $this->delete('/files/exports/'.$own->id)->assertNotFound();
        $this->get('/files/exports')->assertDontSee('Mis archivos.zip');
        // Someone else's file IDs are simply not part of the intruder's files.
        $this->from('/files')->post('/files/exports', ['scope' => 'selection', 'files' => [$this->entry('notas.md')->id]])
            ->assertSessionHasErrors('export');

        app(FileExports::class)->request($this->owner, 'all', null);
        app(FileExports::class)->request($this->owner, 'all', null);
        try {
            app(FileExports::class)->request($this->owner, 'all', null);
            $this->fail('More than three active exports were accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('descargas ZIP activas', $exception->getMessage());
        }

        // Deleting the person is blocked while exports hold disk space.
        $this->expectException(ValidationException::class);
        app(StorageReservations::class)->prepareUserDeletion($this->owner);
    }

    public function test_exports_are_refused_when_the_disk_has_no_room(): void
    {
        $this->seedFiles();
        app(StorageReservations::class)->setPoolLimit('disk', (int) StoragePool::findOrFail('disk')->used_bytes + 10);
        $this->actingAs($this->owner)->from('/files')->post('/files/exports', ['scope' => 'all'])->assertSessionHasErrors('export');
        $this->assertSame(0, FileExport::count());
    }
}
