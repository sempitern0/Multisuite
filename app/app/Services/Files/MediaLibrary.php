<?php

namespace App\Services\Files;

use App\Jobs\SyncMediaLibrary;
use App\Models\Folder;
use App\Models\PrivateUpload;
use App\Models\ServiceAccount;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Provisioning\PersonalLibraries;
use App\Services\Provisioning\ServiceProvisioners;
use App\Services\ServiceAccounts;
use App\Services\Storage\PrivateUploads;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Mirrors each person's connected folders into the directory their service reads:
 * {volume}/.multisuite-media/{service}/{account uuid}[/{section}]/<same sub-folders and names>.
 *
 * Every entry is a hard link to the file stored by Mis archivos, so there is a single copy on
 * disk and the quota counts it once. The mirror is rebuilt from the database (the source of
 * truth): missing links are created, stale ones removed, so running it again after any failure
 * converges. Then the service is asked to create the person's private library, give access to
 * that person only, and scan it.
 */
class MediaLibrary
{
    public function __construct(
        private ConnectedFolders $folders,
        private PrivateUploads $uploads,
        private ServiceAccounts $accounts,
        private ServiceProvisioners $provisioners,
    ) {}

    /** After the current transaction commits and the response is sent, bring the person's mirror up to date. */
    public function changed(User $user): void
    {
        $userId = $user->id;
        DB::afterCommit(fn () => defer(fn () => $this->syncQuietly($userId), 'media-sync-'.$userId));
    }

    public function syncQuietly(int $userId): void
    {
        $user = User::find($userId);
        if ($user === null) {
            return;
        }
        try {
            $this->sync($user);
        } catch (Throwable $exception) {
            // Retried by portal:media-sync; never break the request that triggered it.
            // A synchronous queue cannot delay and would throw into the HTTP response.
            if (config('queue.default') !== 'sync') {
                try {
                    SyncMediaLibrary::dispatch($userId)->delay(now()->addSeconds(30));
                } catch (Throwable $queueException) {
                    Log::warning('media-sync retry unavailable', ['error' => $queueException::class]);
                }
            }
            Log::warning('media-sync failed', ['user' => $userId, 'error' => $exception::class]);
        }
    }

    /** @return int links in place after the run */
    public function sync(User $user): int
    {
        return Cache::lock('media-sync:'.$user->id, 120)->block(30, function () use ($user): int {
            $this->folders->ensure($user);
            $linked = 0;
            $failed = false;
            foreach (Folder::where('user_id', $user->id)->whereNotNull('media_link')->get() as $root) {
                $definition = ConnectedFolders::definition((string) $root->media_link);
                $account = ServiceAccount::where('user_id', $user->id)->where('service', $definition['service'])->first();
                if ($account === null) {
                    continue;
                }
                $directory = $this->directory($definition['service'], $account, $definition['section']);
                // Without access the mirror is emptied: the service must not keep showing it.
                $entitled = $this->accounts->entitled($user, $definition['service']);
                $desired = $entitled ? $this->desired($user, $root) : [];
                $linked += $this->apply($directory, $desired);
                if ($definition['service'] === 'reading') {
                    $this->readingRevision($directory, $desired);
                }
                if ($entitled && $account->status === 'ready' && config('provisioning.'.$definition['service'].'.enabled')) {
                    $provisioner = $this->provisioners->for($definition['service']);
                    if ($provisioner instanceof PersonalLibraries) {
                        try {
                            $provisioner->ensurePersonalLibrary($account, $definition['section'], $definition['library']);
                        } catch (RuntimeException $exception) {
                            $failed = true;
                            Log::warning('personal library not ready', ['user' => $user->id, 'service' => $definition['service'], 'reason' => $exception->getMessage()]);
                        }
                    }
                }
            }

            if ($failed) {
                throw new RuntimeException('La sincronización de bibliotecas necesita un reintento.');
            }

            return $linked;
        });
    }

    /**
     * Publish an opaque revision only when the source mapping changes.
     * State lives outside libraries; neither paths nor personal data leave the NAS.
     *
     * @param  array<string, string>  $desired
     */
    private function readingRevision(string $directory, array $desired): void
    {
        ksort($desired);
        $root = dirname($directory, 2);
        $state = $root.'/.multisuite-state-'.hash('sha256', $directory);
        $digest = hash('sha256', serialize($desired));
        if (is_file($state) && file_get_contents($state) === $digest) {
            return;
        }
        $temporary = $root.'/.multisuite-revision-'.bin2hex(random_bytes(8));
        if (file_put_contents($temporary, bin2hex(random_bytes(16))) === false
            || ! rename($temporary, $root.'/.multisuite-revision')
            || file_put_contents($state, $digest, LOCK_EX) === false) {
            @unlink($temporary);
            throw new RuntimeException('No se puede notificar el cambio de biblioteca.');
        }
    }

    /** Directory of one person's library, created on demand (0755: the service reads it). */
    public function directory(string $service, ServiceAccount $account, ?string $section): string
    {
        [$private] = $this->uploads->privateArea();
        $path = dirname($private).'/.multisuite-media/'.$service.'/'.strtolower($account->remote_id).($section === null ? '' : '/'.$section);
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('No se puede preparar la carpeta del servicio.');
        }

        return $path;
    }

    /**
     * Relative path => stored file, for every current file under the connected folder.
     *
     * @return array<string, string>
     */
    private function desired(User $user, Folder $root): array
    {
        $paths = [$root->id => ''];
        $queue = [$root->id];
        while ($queue !== []) {
            $children = Folder::where('user_id', $user->id)->whereIn('parent_id', $queue)->get(['id', 'parent_id', 'name']);
            $queue = [];
            foreach ($children as $child) {
                $paths[$child->id] = $paths[(int) $child->parent_id].self::segment($child->name).'/';
                $queue[] = $child->id;
            }
        }
        $desired = [];
        $files = UserFile::where('user_id', $user->id)->whereIn('folder_id', array_keys($paths))
            ->whereNull('trashed_at')->whereNull('version_of_id')->get(['id', 'folder_id', 'name', 'upload_id']);
        $uploads = PrivateUpload::whereIn('id', $files->pluck('upload_id'))->get()->keyBy('id');
        foreach ($files as $file) {
            $upload = $uploads->get($file->upload_id);
            $path = $upload === null ? null : $this->uploads->connectedPath($upload);
            if ($path !== null) {
                $relativeDirectory = $paths[(int) $file->folder_id];
                // Kavita's library scanner enumerates child directories, skipping loose root files.
                if ($relativeDirectory === '' && in_array($root->media_link, ['books', 'comics'], true)) {
                    $relativeDirectory = '_lectura-'.$file->id.'/';
                }
                $desired[$relativeDirectory.self::segment($file->name)] = $path;
            }
        }

        return $desired;
    }

    /**
     * @param  array<string, string>  $desired
     * @return int links in place
     */
    private function apply(string $directory, array $desired): int
    {
        foreach ($desired as $relative => $source) {
            $target = $directory.'/'.$relative;
            $parent = dirname($target);
            if (! is_dir($parent) && ! mkdir($parent, 0755, true) && ! is_dir($parent)) {
                throw new RuntimeException('No se puede preparar una subcarpeta del servicio.');
            }
            clearstatcache(true, $target);
            if (! is_link($target) && is_file($target) && fileinode($target) === fileinode($source)) {
                continue;
            }
            // Link next to the target, then rename over it: the service never sees a half-made entry.
            $temporary = $parent.'/.multisuite-'.bin2hex(random_bytes(6));
            if (! link($source, $temporary)) {
                throw new RuntimeException('No se puede enlazar el archivo (¿la carpeta del servicio está en otro disco?).');
            }
            if (! rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('No se puede publicar el archivo en el servicio.');
            }
        }
        $this->prune($directory, $directory, $desired);

        return count($desired);
    }

    /** @param array<string, string> $desired */
    private function prune(string $base, string $directory, array $desired): bool
    {
        $empty = true;
        foreach (scandir($directory) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $directory.'/'.$name;
            $relative = substr($path, strlen($base) + 1);
            if (! is_link($path) && is_dir($path)) {
                if ($this->prune($base, $path, $desired)) {
                    @rmdir($path);
                } else {
                    $empty = false;
                }
            } elseif (! is_link($path) && array_key_exists($relative, $desired)) {
                $empty = false;
            } else {
                @unlink($path);
            }
        }

        return $empty;
    }

    /** A name as one safe path segment (labels never become paths). */
    private static function segment(string $name): string
    {
        $clean = trim(str_replace(['/', '\\', "\0"], '-', $name));

        return $clean === '' || $clean === '.' || $clean === '..' || str_starts_with($clean, '.multisuite-') ? '_'.$clean : $clean;
    }
}
