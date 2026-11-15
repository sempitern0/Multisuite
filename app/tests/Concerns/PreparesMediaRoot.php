<?php

namespace Tests\Concerns;

use App\Models\Folder;
use App\Models\User;
use App\Services\Files\ConnectedFolders;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\PrivateUploads;
use App\Services\Storage\StorageReservations;
use Illuminate\Support\Str;

/**
 * Integration only: a NAS location at MEDIA_TEST_ROOT whose .multisuite-media/{service} folder
 * the real service container mounts as /personal (application.yml). Left in place afterwards:
 * the container keeps the bind mount.
 */
trait PreparesMediaRoot
{
    protected function prepareMediaRoot(string $root): void
    {
        foreach (['/.multisuite-private' => 0700, '/.multisuite-media' => 0755] as $directory => $mode) {
            if (! is_dir($root.$directory)) {
                mkdir($root.$directory, $mode, true);
            }
            chmod($root.$directory, $mode);
        }
        file_put_contents($root.'/.multisuite-volume-id', 'private');
        config(['nas.volumes' => json_encode([['id' => 'private', 'group' => 'disk', 'path' => $root, 'reserve_bytes' => 0]]),
            'nas.uploads_enabled' => true, 'nas.upload_location' => 'private', 'nas.upload_max_bytes' => 1000000]);
        app(StorageReservations::class)->setPoolLimit('disk', 100000000);
    }

    /** Stores $body in the person's connected folder through the real upload path. */
    protected function storeConnected(User $user, string $connection, string $name, string $body): void
    {
        app(StorageReservations::class)->setUserQuota($user, 10000000);
        app(ConnectedFolders::class)->ensure($user);
        $folder = Folder::where('user_id', $user->id)->where('media_link', $connection)->firstOrFail();
        $key = (string) Str::uuid();
        app(PersonalFiles::class)->begin($user, $folder->id, $name, strlen($body), $key);
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        app(PrivateUploads::class)->receive($user, $key, $stream, 0);
        fclose($stream);
    }
}
