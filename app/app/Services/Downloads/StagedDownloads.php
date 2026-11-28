<?php

namespace App\Services\Downloads;

use App\Enums\UploadState;
use App\Models\PrivateUpload;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Files\PersonalFiles;
use App\Services\Storage\PrivateUploads;
use FilesystemIterator;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * What MeTube finished downloading, waiting in the staging folder shared with the portal
 * (docs/DOWNLOADS.md). People never give a path: each file is chosen by an opaque id derived
 * from its place in that folder. Saving it is a normal upload into the person's Mis archivos
 * (quota, type check, encryption or connected folder), then the staged copy is removed.
 */
final class StagedDownloads
{
    /** Bytes per step when copying into Mis archivos; each step is one verified upload chunk. */
    private const CHUNK = 8 * 1024 * 1024;

    private const MAX_DEPTH = 3;

    /** Suffixes yt-dlp and MeTube use while a download is not finished. */
    private const PARTIAL = ['part', 'ytdl', 'tmp', 'temp'];

    public function __construct(private PersonalFiles $files, private PrivateUploads $uploads) {}

    /** Real staging folder, or null when Descargas is off or the folder is missing. */
    public static function root(): ?string
    {
        $path = (string) config('portal.downloads_staging_path');
        $real = $path === '' ? false : realpath($path);

        return DownloadsAccess::enabled() && $real !== false && is_dir($real) ? rtrim($real, '/') : null;
    }

    /**
     * Finished downloads, newest first.
     *
     * @return list<array{id: string, name: string, folder: string, bytes: int, modified: int}>
     */
    public function all(): array
    {
        $root = self::root();
        if ($root === null) {
            return [];
        }
        $items = [];
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        $walk->setMaxDepth(self::MAX_DEPTH);
        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            $relative = substr($entry->getPathname(), strlen($root) + 1);
            if (! $this->finished($entry, $relative)) {
                continue;
            }
            $folder = dirname($relative);
            $items[] = [
                'id' => self::id($relative),
                'name' => $entry->getFilename(),
                'folder' => $folder === '.' ? '' : $folder,
                'bytes' => (int) $entry->getSize(),
                'modified' => (int) $entry->getMTime(),
            ];
        }
        usort($items, fn (array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return $items;
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * Copies a finished download into the person's Mis archivos (folder of theirs, or the root) and
     * removes it from the staging folder. Refused types or full quota keep it staged.
     */
    public function save(User $user, string $id, ?int $folderId): UserFile
    {
        [$path, $item] = $this->locate($id);
        $key = $this->attemptKey($user, $id, $item);
        $file = $this->files->begin($user, $folderId, $item['name'], $item['bytes'], $key);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('No se ha podido leer ese archivo de Descargas.');
        }
        try {
            $offset = $this->uploads->received($user, $key);
            fseek($handle, $offset);
            while ($offset < $item['bytes']) {
                $data = fread($handle, self::CHUNK);
                if ($data === false || $data === '') {
                    throw new RuntimeException('El archivo de Descargas ha cambiado mientras se guardaba.');
                }
                $chunk = fopen('php://temp', 'w+b');
                if ($chunk === false) {
                    throw new RuntimeException('No se ha podido preparar la copia.');
                }
                fwrite($chunk, $data);
                rewind($chunk);
                try {
                    $this->uploads->receive($user, $key, $chunk, $offset);
                } finally {
                    fclose($chunk);
                }
                $offset += strlen($data);
            }
        } catch (Throwable $exception) {
            $this->cancelQuietly($user, $key); // Releases the reserved space; the download stays staged.
            throw $exception;
        } finally {
            fclose($handle);
        }
        @unlink($path);

        return $file->refresh();
    }

    /**
     * Same file, same person: retrying continues a pending upload (or finds the one that finished
     * just before the staged copy could be removed) instead of reserving twice. A cancelled or
     * already deleted attempt is final for its key, so the next attempt starts with a fresh one.
     *
     * @param  array{id: string, name: string, folder: string, bytes: int, modified: int}  $item
     */
    private function attemptKey(User $user, string $id, array $item): string
    {
        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'multisuite-download:'.$user->id.':'.$id.':'.$item['bytes'].':'.$item['modified'])->toString();
        $previous = PrivateUpload::whereKey($key)->value('state');

        return in_array($previous, [UploadState::Cancelled->value, UploadState::Purged->value], true) ? Uuid::uuid4()->toString() : $key;
    }

    public function discard(string $id): void
    {
        [$path] = $this->locate($id);
        if (! @unlink($path)) {
            throw new RuntimeException('No se ha podido descartar ese archivo.');
        }
    }

    /** @return array{0: string, 1: array{id: string, name: string, folder: string, bytes: int, modified: int}} */
    private function locate(string $id): array
    {
        $root = self::root();
        foreach ($root === null ? [] : $this->all() as $item) {
            if (hash_equals($item['id'], $id)) {
                $path = $root.'/'.($item['folder'] === '' ? '' : $item['folder'].'/').$item['name'];
                $real = realpath($path);
                // Re-checked at use: still a regular file inside the staging folder, not a link.
                if ($real !== false && $real === $path && str_starts_with($real, $root.'/') && is_file($real) && ! is_link($real)) {
                    return [$real, $item];
                }
            }
        }
        throw new RuntimeException('Ese archivo ya no está en Descargas.');
    }

    private function finished(SplFileInfo $entry, string $relative): bool
    {
        if ($entry->isLink() || ! $entry->isFile()) {
            return false;
        }
        foreach (explode('/', $relative) as $part) {
            if (str_starts_with($part, '.')) {
                return false; // Hidden files and folders (MeTube's own state, temporary data).
            }
        }

        return ! in_array(strtolower($entry->getExtension()), self::PARTIAL, true) && $entry->getSize() > 0;
    }

    private static function id(string $relative): string
    {
        return substr(hash_hmac('sha256', 'download:'.$relative, (string) config('app.key')), 0, 32);
    }

    private function cancelQuietly(User $user, string $key): void
    {
        try {
            $this->uploads->cancel($user, $key);
        } catch (Throwable $exception) {
            Log::warning('Descargas: no se pudo cancelar una copia incompleta.', ['exception' => $exception::class]);
        }
    }
}
