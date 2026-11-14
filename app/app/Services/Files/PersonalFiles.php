<?php

namespace App\Services\Files;

use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Storage\PrivateUploads;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Mis archivos": per-person folders and file entries on top of PrivateUploads.
 * Every lookup is scoped to its owner; administrators get no implicit access.
 */
class PersonalFiles
{
    public const MAX_DEPTH = 8;

    public function __construct(private PrivateUploads $uploads) {}

    /** Display names are labels only: they never become paths on disk. */
    public static function cleanName(string $name, int $max): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', ' ', $name) ?? '';
        $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .\t");
        $name = Str::limit($name, $max, '');
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Escribe un nombre válido.');
        }

        return $name;
    }

    public function folder(User $user, ?int $id): ?Folder
    {
        return $id === null ? null : Folder::where('user_id', $user->id)->findOrFail($id);
    }

    public function file(User $user, int $id): UserFile
    {
        return UserFile::where('user_id', $user->id)->findOrFail($id);
    }

    /** @return list<Folder> Root first, current folder last. */
    public function breadcrumbs(?Folder $folder): array
    {
        $trail = [];
        while ($folder !== null && count($trail) <= self::MAX_DEPTH) {
            array_unshift($trail, $folder);
            $folder = $folder->parent_id === null ? null : Folder::where('user_id', $folder->user_id)->find($folder->parent_id);
        }

        return $trail;
    }

    /** @return Collection<int, Folder> */
    public function folders(User $user, ?Folder $parent): Collection
    {
        return Folder::where('user_id', $user->id)->where('parent_id', $parent?->id)->orderBy('name')->get();
    }

    /**
     * Ready files with their verified size and MIME; uploads in progress are counted apart.
     *
     * @return Collection<int, UserFile>
     */
    public function files(User $user, ?Folder $folder): Collection
    {
        return $this->visible($user)
            ->where('user_files.folder_id', $folder?->id)
            ->orderBy('user_files.name')
            ->get(['user_files.*', 'private_uploads.mime as mime', 'storage_reservations.actual_bytes as size']);
    }

    /**
     * Confirmed entries of one person joined with their verified MIME and size.
     * $scope: 'current' (listed in folders), 'trash' (trashed current files) or 'versions'.
     *
     * @return Builder<UserFile>
     */
    private function confirmed(User $user, string $scope): Builder
    {
        $query = UserFile::query()
            ->join('private_uploads', 'private_uploads.id', '=', 'user_files.upload_id')
            ->join('storage_reservations', 'storage_reservations.id', '=', 'user_files.upload_id')
            ->where('user_files.user_id', $user->id)
            ->where('private_uploads.state', 'ready');

        return match ($scope) {
            'current' => $query->whereNull('user_files.trashed_at')->whereNull('user_files.version_of_id'),
            'trash' => $query->whereNotNull('user_files.trashed_at')->whereNull('user_files.version_of_id'),
            'versions' => $query->whereNotNull('user_files.version_of_id'),
            default => throw new RuntimeException('Ámbito de archivos desconocido.'),
        };
    }

    /**
     * Every current file of a person with its verified size (for ZIP exports).
     *
     * @return Collection<int, UserFile>
     */
    public function currentFiles(User $user): Collection
    {
        return $this->visible($user)->orderBy('user_files.name')
            ->get(['user_files.*', 'storage_reservations.actual_bytes as size']);
    }

    /** @return Builder<UserFile> */
    private function visible(User $user): Builder
    {
        return $this->confirmed($user, 'current');
    }

    /**
     * Exact item counts and sizes for every folder of a person, computed from the
     * verified bytes stored for each file (not estimated from the disk).
     * Key 0 is the root; "items" counts direct children, "bytes" includes subfolders.
     *
     * @return array<int, array{items: int, bytes: int}>
     */
    public function summaries(User $user): array
    {
        $children = [];
        foreach (Folder::where('user_id', $user->id)->get(['id', 'parent_id']) as $folder) {
            $children[$folder->parent_id ?? 0][] = $folder->id;
        }
        $direct = [];
        $rows = $this->visible($user)
            ->groupBy('user_files.folder_id')
            ->selectRaw('user_files.folder_id as folder_key, count(*) as files, coalesce(sum(storage_reservations.actual_bytes), 0) as bytes')
            ->toBase()->get();
        foreach ($rows as $row) {
            $direct[(int) ($row->folder_key ?? 0)] = ['files' => (int) $row->files, 'bytes' => (int) $row->bytes];
        }

        $summaries = [];
        $walk = function (int $id, int $depth) use (&$walk, &$summaries, $children, $direct): int {
            $bytes = $direct[$id]['bytes'] ?? 0;
            // Depth guard: the tree is acyclic by construction, this only bounds the walk.
            if ($depth <= self::MAX_DEPTH) {
                foreach ($children[$id] ?? [] as $child) {
                    $bytes += $walk($child, $depth + 1);
                }
            }
            $summaries[$id] = ['items' => count($children[$id] ?? []) + ($direct[$id]['files'] ?? 0), 'bytes' => $bytes];

            return $bytes;
        };
        $walk(0, 0);

        return $summaries;
    }

    public function pendingCount(User $user): int
    {
        return UserFile::where('user_files.user_id', $user->id)
            ->join('private_uploads', 'private_uploads.id', '=', 'user_files.upload_id')
            ->where('private_uploads.state', 'pending')->count();
    }

    public function createFolder(User $user, ?int $parentId, string $name): Folder
    {
        $name = self::cleanName($name, 120);

        return DB::transaction(function () use ($user, $parentId, $name): Folder {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $parent = $this->folder($user, $parentId);
            if (count($this->breadcrumbs($parent)) >= self::MAX_DEPTH) {
                throw new RuntimeException('Se ha alcanzado el máximo de '.self::MAX_DEPTH.' niveles de carpetas.');
            }
            $this->assertFreeFolderName($user, $parent?->id, $name, null);
            $folder = new Folder;
            $folder->user_id = $user->id;
            $folder->parent_id = $parent?->id;
            $folder->name = $name;
            $folder->save();

            return $folder;
        });
    }

    public function renameFolder(User $user, int $id, string $name): Folder
    {
        $name = self::cleanName($name, 120);

        return DB::transaction(function () use ($user, $id, $name): Folder {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $folder = Folder::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            $this->assertFreeFolderName($user, $folder->parent_id, $name, $folder->id);
            $folder->name = $name;
            $folder->save();

            return $folder;
        });
    }

    /** Only empty folders: content removal goes through the trash (S03.3). */
    public function deleteEmptyFolder(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $folder = Folder::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if (Folder::where('parent_id', $folder->id)->exists() || UserFile::where('folder_id', $folder->id)->exists()) {
                throw new RuntimeException('La carpeta no está vacía.');
            }
            $folder->delete();
        });
    }

    /**
     * Reserves quota and registers the entry before any byte is sent; the client then
     * streams the body to PUT /uploads/{key}. Retrying the same key is idempotent.
     */
    public function begin(User $user, ?int $folderId, string $name, int $bytes, string $key): UserFile
    {
        $name = self::cleanName($name, 200);

        return DB::transaction(function () use ($user, $folderId, $name, $bytes, $key): UserFile {
            // Ownership of the target folder first: a foreign folder must not reserve quota.
            $folder = $this->folder($user, $folderId);
            $upload = $this->uploads->begin($user, $bytes, $key); // Locks the user row.
            $existing = UserFile::where('upload_id', $upload->id)->first();
            if ($existing !== null) {
                if ($existing->user_id !== $user->id) {
                    throw new RuntimeException('Operación no disponible para este usuario.');
                }

                return $existing;
            }
            $file = new UserFile;
            $file->user_id = $user->id;
            $file->folder_id = $folder?->id;
            $file->upload_id = $upload->id;
            // Same name in the same folder: becomes the new version once its bytes are verified.
            $file->name = $name;
            $file->save();

            return $file;
        });
    }

    public function renameFile(User $user, int $id, string $name): UserFile
    {
        $name = self::cleanName($name, 200);

        return DB::transaction(function () use ($user, $id, $name): UserFile {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $file = UserFile::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if (! $file->isCurrent()) {
                throw new RuntimeException('Solo se puede renombrar la versión actual de un archivo fuera de la papelera.');
            }
            if (strcasecmp($file->name, $name) !== 0 && $this->nameTaken($user, $file->folder_id, $name, $file->id)) {
                throw new RuntimeException('Ya existe un archivo con ese nombre en esta carpeta.');
            }
            $file->name = $name;
            $file->save();

            return $file;
        });
    }

    /** @return Collection<int, UserFile> */
    public function trashed(User $user): Collection
    {
        return $this->confirmed($user, 'trash')->orderByDesc('user_files.trashed_at')
            ->get(['user_files.*', 'private_uploads.mime as mime', 'storage_reservations.actual_bytes as size']);
    }

    /** Bytes held by the trash, including the versions of trashed files. */
    public function trashBytes(User $user): int
    {
        $trashed = $this->confirmed($user, 'trash')->pluck('user_files.id');
        $versions = $this->confirmed($user, 'versions')->whereIn('user_files.version_of_id', $trashed);

        return (int) $this->confirmed($user, 'trash')->sum('storage_reservations.actual_bytes')
            + (int) $versions->sum('storage_reservations.actual_bytes');
    }

    /** @return Collection<int, UserFile> Newest first. */
    public function versions(User $user, UserFile $file): Collection
    {
        return $this->confirmed($user, 'versions')->where('user_files.version_of_id', $file->id)
            ->orderByDesc('user_files.superseded_at')->orderByDesc('user_files.id')
            ->get(['user_files.*', 'private_uploads.mime as mime', 'storage_reservations.actual_bytes as size']);
    }

    /** Moves a current file (and, implicitly, its versions) to the trash. Bytes stay counted. */
    public function trash(User $user, int $id): UserFile
    {
        return DB::transaction(function () use ($user, $id): UserFile {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $file = UserFile::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if (! $file->isCurrent()) {
                throw new RuntimeException('Este archivo ya está en la papelera o es una versión anterior.');
            }
            $file->original_folder_id = $file->folder_id;
            $file->folder_id = null;
            $file->trashed_at = now();
            $file->save();

            return $file;
        });
    }

    /** Back to its folder (root if it no longer exists); a name clash gets a numbered copy. */
    public function restore(User $user, int $id): UserFile
    {
        return DB::transaction(function () use ($user, $id): UserFile {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $file = UserFile::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            if ($file->trashed_at === null || $file->version_of_id !== null) {
                throw new RuntimeException('Este archivo no está en la papelera.');
            }
            $folder = $file->original_folder_id === null ? null
                : Folder::where('user_id', $user->id)->find($file->original_folder_id);
            $file->folder_id = $folder?->id;
            $file->original_folder_id = null;
            $file->trashed_at = null;
            $file->name = $this->freeFileName($user, $folder?->id, $file->name, $file->id);
            $file->save();

            return $file;
        });
    }

    /**
     * Permanent deletion of a trashed file (with its versions) or of a single older
     * version. Each blob is purged in its own transaction, versions first, so a
     * failure never leaves a counted entry without bytes or bytes without an entry.
     */
    public function purge(User $user, int $id): void
    {
        $file = UserFile::where('user_id', $user->id)->findOrFail($id);
        if ($file->isCurrent()) {
            throw new RuntimeException('Mueve el archivo a la papelera antes de borrarlo definitivamente.');
        }
        if ($file->version_of_id === null) {
            foreach (UserFile::where('user_id', $user->id)->where('version_of_id', $file->id)->orderBy('id')->get() as $version) {
                $this->purgeOne($user, $version);
            }
        }
        $this->purgeOne($user, $file);
    }

    /** @return int Number of files that could not be purged (kept and still counted). */
    public function emptyTrash(User $user): int
    {
        $failed = 0;
        foreach (UserFile::where('user_id', $user->id)->whereNotNull('trashed_at')->whereNull('version_of_id')->pluck('id') as $id) {
            try {
                $this->purge($user, (int) $id);
            } catch (RuntimeException) {
                $failed++;
            }
        }

        return $failed;
    }

    /** Makes an older version current; the previous current becomes its newest version. */
    public function promote(User $user, int $versionId): UserFile
    {
        return DB::transaction(function () use ($user, $versionId): UserFile {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $version = UserFile::where('user_id', $user->id)->lockForUpdate()->findOrFail($versionId);
            $current = $version->version_of_id === null ? null
                : UserFile::where('user_id', $user->id)->lockForUpdate()->find($version->version_of_id);
            if ($current === null || ! $current->isCurrent()) {
                throw new RuntimeException('Solo se puede restaurar una versión de un archivo que no esté en la papelera.');
            }
            $this->swap($current, $version, true);

            return $version;
        });
    }

    /**
     * Listener of PrivateUploadReady (same transaction as the commit): if a confirmed
     * file with the same name already exists in the folder, it becomes a version.
     */
    public function onUploadReady(string $uploadId, int $userId): void
    {
        $new = UserFile::where('upload_id', $uploadId)->where('user_id', $userId)->lockForUpdate()->first();
        if ($new === null || ! $new->isCurrent()) {
            return;
        }
        $user = User::findOrFail($userId);
        $previous = $this->visible($user)
            ->where('user_files.folder_id', $new->folder_id)
            ->where('user_files.id', '!=', $new->id)
            ->whereRaw('LOWER(user_files.name) = ?', [Str::lower($new->name)])
            ->orderByDesc('user_files.id')->lockForUpdate()->first(['user_files.*']);
        if ($previous !== null) {
            $this->swap($previous, $new, false);
        }
    }

    /**
     * Retention (portal:files-purge): trash older than $trashDays and versions beyond the
     * newest $keepVersions per file. Returns [purged, failed].
     *
     * @return array{int, int}
     */
    public function enforceRetention(int $trashDays, int $keepVersions): array
    {
        $purged = 0;
        $failed = 0;
        $run = function (UserFile $file) use (&$purged, &$failed): void {
            try {
                $this->purge(User::findOrFail($file->user_id), $file->id);
                $purged++;
            } catch (RuntimeException) {
                $failed++;
            }
        };
        // Collect first: purging deletes rows, which would shift chunked pagination.
        UserFile::whereNotNull('trashed_at')->whereNull('version_of_id')
            ->where('trashed_at', '<', now()->subDays(max(1, $trashDays)))->orderBy('id')->get()->each($run);
        $heads = UserFile::whereNotNull('version_of_id')->select('version_of_id')->groupBy('version_of_id')
            ->havingRaw('count(*) > ?', [max(0, $keepVersions)])->pluck('version_of_id');
        foreach ($heads as $head) {
            UserFile::where('version_of_id', $head)->orderByDesc('superseded_at')->orderByDesc('id')
                ->offset(max(0, $keepVersions))->limit(100000)->get()->each($run);
        }

        return [$purged, $failed];
    }

    /**
     * $old leaves the folder as newest version of $new, which inherits $old's versions.
     * Restoring a version keeps the visible name; a new upload keeps its own spelling.
     */
    private function swap(UserFile $old, UserFile $new, bool $keepName): void
    {
        UserFile::where('version_of_id', $old->id)->where('id', '!=', $new->id)->update(['version_of_id' => $new->id]);
        $new->version_of_id = null;
        $new->superseded_at = null;
        $new->folder_id = $old->folder_id;
        if ($keepName) {
            $new->name = $old->name;
        }
        $new->save();
        $old->version_of_id = $new->id;
        $old->superseded_at = now();
        $old->folder_id = null;
        $old->save();
    }

    private function purgeOne(User $user, UserFile $file): void
    {
        DB::transaction(function () use ($user, $file): void {
            $this->uploads->purge($user, $file->upload_id);
            UserFile::whereKey($file->id)->delete();
        });
    }

    /** @return array{path: string, mime: string, size: int} */
    public function readable(User $user, UserFile $file): array
    {
        $path = $this->uploads->readablePath($user, $file->upload_id);
        $upload = $file->upload()->firstOrFail();
        $reservation = $file->reservation()->firstOrFail();

        return ['path' => $path, 'mime' => (string) $upload->mime, 'size' => (int) $reservation->actual_bytes];
    }

    private function assertFreeFolderName(User $user, ?int $parentId, string $name, ?int $ignore): void
    {
        $taken = Folder::where('user_id', $user->id)->where('parent_id', $parentId)
            ->when($ignore !== null, fn ($query) => $query->whereKeyNot($ignore))
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])->exists();
        if ($taken) {
            throw new RuntimeException('Ya existe una carpeta con ese nombre aquí.');
        }
    }

    private function nameTaken(User $user, ?int $folderId, string $name, ?int $ignore): bool
    {
        return UserFile::where('user_files.user_id', $user->id)->where('user_files.folder_id', $folderId)
            ->join('private_uploads', 'private_uploads.id', '=', 'user_files.upload_id')
            ->whereIn('private_uploads.state', ['pending', 'ready'])
            ->whereNull('user_files.trashed_at')->whereNull('user_files.version_of_id')
            ->when($ignore !== null, fn ($query) => $query->where('user_files.id', '!=', $ignore))
            ->whereRaw('LOWER(user_files.name) = ?', [Str::lower($name)])->exists();
    }

    /** "foto.jpg" → "foto (2).jpg" while the name is in use in the same folder. */
    private function freeFileName(User $user, ?int $folderId, string $name, ?int $ignore): string
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
        $candidate = $name;
        for ($i = 2; $this->nameTaken($user, $folderId, $candidate, $ignore); $i++) {
            if ($i > 500) {
                throw new RuntimeException('Hay demasiados archivos con ese nombre.');
            }
            $candidate = $stem.' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
        }

        return $candidate;
    }
}
