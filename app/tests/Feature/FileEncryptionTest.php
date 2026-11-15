<?php

namespace Tests\Feature;

use App\Models\FileExport;
use App\Models\PrivateUpload;
use App\Services\Storage\FileCipher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\ReadsEncryptedFiles;
use Tests\Concerns\UsesPrivateStorage;
use Tests\TestCase;

class FileEncryptionTest extends TestCase
{
    use ReadsEncryptedFiles, RefreshDatabase, UsesPrivateStorage;

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

    private function path(string $id, string $suffix = '.blob'): string
    {
        return $this->root.'/.multisuite-private/'.$id.$suffix;
    }

    public function test_files_are_encrypted_at_rest_and_served_in_plain_with_ranges(): void
    {
        $body = str_repeat("Factura de la luz: 125 kWh\n", 4000); // > one 64 KiB chunk
        $this->upload($this->owner, 'luz.txt', $body)->assertOk();
        $file = $this->entry('luz.txt');
        $raw = (string) file_get_contents($this->path($file->upload_id));

        $this->assertStringNotContainsString('Factura de la luz', $raw);
        $this->assertStringStartsWith('MSF1', $raw);
        $this->assertSame(FileCipher::FORMAT, PrivateUpload::findOrFail($file->upload_id)->cipher);
        $this->assertFileDoesNotExist($this->path($file->upload_id, '.part'));
        $this->assertSame($body, $this->get('/files/'.$file->id.'/content?download=1')->assertOk()->streamedContent());

        // A range crossing the chunk boundary, the open-ended form and an impossible one.
        $part = $this->get('/files/'.$file->id.'/content', ['Range' => 'bytes=65530-65545'])->assertStatus(206);
        $this->assertSame(substr($body, 65530, 16), $part->streamedContent());
        $this->assertSame('bytes 65530-65545/'.strlen($body), $part->headers->get('Content-Range'));
        $this->assertSame(substr($body, -10), $this->get('/files/'.$file->id.'/content', ['Range' => 'bytes=-10'])->assertStatus(206)->streamedContent());
        $this->get('/files/'.$file->id.'/content', ['Range' => 'bytes='.strlen($body).'-'])->assertStatus(416);

        Livewire::test('pages::files.show', ['fileId' => $file->id])->assertSee('Factura de la luz: 125 kWh');
    }

    public function test_zip_exports_are_streamed_encrypted_and_downloaded_in_plain(): void
    {
        $this->upload($this->owner, 'agua.txt', 'm3 14')->assertOk();
        $this->upload($this->owner, 'gas.txt', 'kWh 300')->assertOk();
        Livewire::test('pages::files.index')->call('export', 'all');
        $export = FileExport::sole();
        $this->assertSame('ready', $export->state);
        $this->assertSame(FileCipher::FORMAT, $export->cipher);
        $this->assertStringNotContainsString('kWh 300', (string) file_get_contents($this->path($export->id, '.zip')));
        $this->assertSame([], glob($this->root.'/.multisuite-private/*.zip.part'));

        $zip = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($zip, $this->get('/files/exports/'.$export->id.'/download')->assertOk()->streamedContent());
        $archive = new \ZipArchive;
        $this->assertTrue($archive->open($zip, \ZipArchive::CHECKCONS) === true);
        $this->assertSame('kWh 300', $archive->getFromName('gas.txt'));
        $this->assertSame('m3 14', $archive->getFromName('agua.txt'));
        $archive->close();
        unlink($zip);
    }

    public function test_without_the_key_nothing_new_is_stored_and_encrypted_files_stay_closed(): void
    {
        $this->upload($this->owner, 'nota.txt', 'secreto')->assertOk();
        $file = $this->entry('nota.txt');

        config(['nas.files_key' => '']);
        $this->actingAs($this->owner)->postJson('/files', ['key' => (string) \Illuminate\Support\Str::uuid(), 'bytes' => 3, 'name' => 'otra.txt'])
            ->assertStatus(422);
        $this->get('/files/'.$file->id.'/content')->assertNotFound();

        // A different key cannot open it either (authenticated encryption).
        config(['nas.files_key' => FileCipher::generateKey()]);
        Livewire::test('pages::files.show', ['fileId' => $file->id])->assertSee('dañado o la clave no es la correcta');
    }

    public function test_files_stored_before_encryption_remain_readable(): void
    {
        config(['nas.encrypt_files' => false]);
        $this->upload($this->owner, 'antiguo.txt', 'sin cifrar')->assertOk();
        $file = $this->entry('antiguo.txt');
        $this->assertNull(PrivateUpload::findOrFail($file->upload_id)->cipher);
        $this->assertSame('sin cifrar', (string) file_get_contents($this->path($file->upload_id)));

        config(['nas.encrypt_files' => true]);
        $this->assertSame('sin cifrar', $this->get('/files/'.$file->id.'/content?download=1')->assertOk()->streamedContent());
    }
}
