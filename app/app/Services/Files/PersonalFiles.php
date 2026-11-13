<?php

namespace App\Services\Files;

use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Storage\PrivateUploads;
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
        return UserFile::query()
            ->join('private_uploads', 'private_uploads.id', '=', 'user_files.upload_id')
            ->join('storage_reservations', 'storage_reservations.id', '=', 'user_files.upload_id')
            ->where('user_files.user_id', $user->id)
            ->where('user_files.folder_id', $folder?->id)
            ->where('private_uploads.state', 'ready')
            ->orderBy('user_files.name')
            ->get(['user_files.*', 'private_uploads.mime as mime', 'storage_reservations.actual_bytes as size']);
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
            $file->name = $this->freeFileName($user, $folder?->id, $name, null);
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
            if (strcasecmp($file->name, $name) !== 0 && $this->nameTaken($user, $file->folder_id, $name, $file->id)) {
                throw new RuntimeException('Ya existe un archivo con ese nombre en esta carpeta.');
            }
            $file->name = $name;
            $file->save();

            return $file;
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
