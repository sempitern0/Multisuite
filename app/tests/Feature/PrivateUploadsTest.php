<?php

namespace Tests\Feature;

use App\Models\StoragePool;
use App\Models\User;
use App\Services\Storage\PrivateUploads;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class PrivateUploadsTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/multisuite-upload-'.Str::uuid();
        mkdir($this->root, 0700);
        mkdir($this->root.'/.multisuite-private', 0700);
        file_put_contents($this->root.'/.multisuite-volume-id', 'private');
        config(['nas.volumes' => json_encode([['id' => 'private', 'group' => 'disk', 'path' => $this->root, 'reserve_bytes' => 0]]),
            'nas.uploads_enabled' => true, 'nas.upload_location' => 'private', 'nas.upload_max_bytes' => 1000]);
        $this->user = User::factory()->create();
        app(StorageReservations::class)->setPoolLimit('disk', 1000);
        app(StorageReservations::class)->setUserQuota($this->user, 1000);
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

    private function receive(string $id, string $body): void
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $body);
        rewind($stream);
        try {
            app(PrivateUploads::class)->receive($this->user, $id, $stream);
        } finally {
            fclose($stream);
        }
    }

    public function test_private_file_commits_verified_bytes_and_retries_do_not_double_count(): void
    {
        $id = (string) Str::uuid();
        $body = 'Private document';
        $uploads = app(PrivateUploads::class);
        $uploads->begin($this->user, strlen($body), $id);
        $uploads->begin($this->user, strlen($body), $id);
        $this->receive($id, $body);
        $this->receive($id, 'ignored replay');
        $this->assertSame($body, file_get_contents($this->root.'/.multisuite-private/'.$id.'.blob'));
        $this->assertDatabaseHas('private_uploads', ['id' => $id, 'state' => 'ready', 'sha256' => hash('sha256', $body)]);
        $this->assertSame(strlen($body), StoragePool::findOrFail('disk')->used_bytes);
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->expectException(RuntimeException::class);
        $uploads->cancel($this->user, $id);
    }

    public function test_oversized_stream_keeps_reservation_until_verified_cleanup(): void
    {
        $id = (string) Str::uuid();
        app(PrivateUploads::class)->begin($this->user, 3, $id);
        try {
            $this->receive($id, 'too large');
            $this->fail('Oversized body accepted');
        } catch (RuntimeException) {
            $this->assertSame(3, StoragePool::findOrFail('disk')->reserved_bytes);
        }
        app(PrivateUploads::class)->cancel($this->user, $id);
        app(PrivateUploads::class)->cancel($this->user, $id);
        $this->assertFileDoesNotExist($this->root.'/.multisuite-private/'.$id.'.part');
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
    }

    public function test_truncated_and_html_files_are_not_committed(): void
    {
        foreach (['short', '<html><body>unsafe</body></html>'] as $body) {
            $id = (string) Str::uuid();
            app(PrivateUploads::class)->begin($this->user, $body === 'short' ? 20 : strlen($body), $id);
            try {
                $this->receive($id, $body);
                $this->fail('Invalid content accepted');
            } catch (RuntimeException) {
                $this->assertDatabaseHas('private_uploads', ['id' => $id, 'state' => 'pending']);
            }
            app(PrivateUploads::class)->cancel($this->user, $id);
        }
        $this->assertSame(0, StoragePool::findOrFail('disk')->used_bytes);
    }

    public function test_cleanup_recovers_crash_after_rename_but_before_database_commit(): void
    {
        $id = (string) Str::uuid();
        app(PrivateUploads::class)->begin($this->user, 10, $id);
        $path = $this->root.'/.multisuite-private/'.$id.'.blob';
        file_put_contents($path, 'uncommitted');
        $this->travel(31)->minutes();
        $this->artisan('portal:uploads-cleanup')->assertExitCode(0);
        $this->assertFileDoesNotExist($path);
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->assertDatabaseHas('private_uploads', ['id' => $id, 'state' => 'cancelled']);
    }

    public function test_missing_volume_and_symlinks_never_release_reservations(): void
    {
        $id = (string) Str::uuid();
        app(PrivateUploads::class)->begin($this->user, 10, $id);
        $path = $this->root.'/.multisuite-private/'.$id.'.part';
        symlink($this->root.'/.multisuite-volume-id', $path);
        $this->travel(31)->minutes();
        $this->artisan('portal:uploads-cleanup')->assertExitCode(1);
        $this->assertSame(10, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->assertSame('private', file_get_contents($this->root.'/.multisuite-volume-id'));
        unlink($path);
        file_put_contents($this->root.'/.multisuite-volume-id', 'wrong-volume');
        $this->artisan('portal:uploads-cleanup')->assertExitCode(1);
        $this->assertSame(10, StoragePool::findOrFail('disk')->reserved_bytes);
    }

    public function test_http_endpoints_enforce_ownership_and_disabled_admission(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->user)->postJson('/uploads', ['key' => $id, 'bytes' => 10])->assertCreated();
        $other = User::factory()->create();
        $this->actingAs($other)->getJson('/uploads/'.$id)->assertNotFound();
        $this->deleteJson('/uploads/'.$id)->assertNotFound();
        $this->assertSame(10, StoragePool::findOrFail('disk')->reserved_bytes);
        config(['nas.uploads_enabled' => false]);
        $this->actingAs($this->user)->postJson('/uploads', ['key' => (string) Str::uuid(), 'bytes' => 10])->assertUnprocessable();
        $this->deleteJson('/uploads/'.$id)->assertOk();
    }

    public function test_http_raw_upload_and_state(): void
    {
        $id = (string) Str::uuid();
        $this->actingAs($this->user)->postJson('/uploads', ['key' => $id, 'bytes' => 5])->assertCreated();
        $this->call('PUT', '/uploads/'.$id, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], 'hello')
            ->assertOk()->assertJsonPath('state', 'ready');
        $this->getJson('/uploads/'.$id)->assertOk()->assertExactJson(['id' => $id, 'state' => 'ready']);
    }
}
