<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Models\UserFile;
use App\Services\Storage\StorageReservations;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * A disposable private location (marker + 0700 directory) with a pool and an owner
 * quota, plus the browser upload flow. Call setUpPrivateStorage()/tearDownPrivateStorage().
 */
trait UsesPrivateStorage
{
    protected string $root;

    protected User $owner;

    protected function setUpPrivateStorage(): void
    {
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

    protected function tearDownPrivateStorage(): void
    {
        foreach (glob($this->root.'/.multisuite-private/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->root.'/.multisuite-private');
        @unlink($this->root.'/.multisuite-volume-id');
        rmdir($this->root);
    }

    /** Full browser flow: register + reserve, then raw PUT. */
    protected function upload(User $user, string $name, string $body, ?int $folder = null): TestResponse
    {
        $key = (string) Str::uuid();
        $this->actingAs($user)->postJson('/files', ['key' => $key, 'bytes' => strlen($body), 'name' => $name, 'folder' => $folder])->assertCreated();

        return $this->call('PUT', '/uploads/'.$key, [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $body);
    }

    protected function entry(string $name): UserFile
    {
        return UserFile::where('name', $name)->whereNull('version_of_id')->firstOrFail();
    }
}
