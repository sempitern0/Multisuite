<?php

namespace App\Services\Files;

use App\Models\FileExport;
use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
use App\Services\Storage\PrivateUploads;
use App\Services\Storage\StorageReservations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * ZIP downloads of a selection, a folder or everything. The archive is written to the
 * private area under a temporary reservation (disk space, not the person's quota),
 * offered for a limited time and then deleted before the reservation is released.
 * Uploaded archives are never extracted: files are stored as-is (no compression).
 */
class FileExports
{
    public const MAX_ITEMS = 10000;

    public const MAX_ACTIVE = 3;

    public function __construct(
        private PersonalFiles $files,
        private PrivateUploads $uploads,
        private StorageReservations $reservations,
    ) {}

    /**
     * @param  'selection'|'folder'|'all'  $scope
     * @param  list<int>  $fileIds
     * @param  list<int>  $folderIds
     */
    public function request(User $user, string $scope, ?int $folderId, array $fileIds = [], array $folderIds = []): FileExport
    {
        $base = $this->files->folder($user, $folderId);
        $tree = $this->tree($user);
        $items = match ($scope) {
            'all' => $this->collect($user, $tree, null, [], [], true),
            'folder' => $this->collect($user, $tree, $base, [], [], true),
            'selection' => $this->collect($user, $tree, $base, $fileIds, $folderIds, false),
        };
        if ($items === []) {
            throw new RuntimeException('No hay archivos que descargar en lo que has elegido.');
        }
        if (count($items) > self::MAX_ITEMS) {
            throw new RuntimeException('Demasiados archivos para un solo ZIP (máximo '.self::MAX_ITEMS.'). Elige carpetas más pequeñas.');
        }
        $name = match ($scope) {
            'all' => 'Mis archivos',
            'folder' => $base->name ?? 'Mis archivos',
            'selection' => 'Selección '.now()->format('Y-m-d H.i'),
        }.'.zip';
        [$root, $pool, $location] = $this->uploads->privateArea();
        $estimate = $this->estimate($items);
        $key = (string) Str::uuid();

        $export = DB::transaction(function () use ($user, $items, $name, $root, $pool, $location, $estimate, $key): FileExport {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (FileExport::where('user_id', $user->id)->whereIn('state', ['queued', 'building', 'ready'])->count() >= self::MAX_ACTIVE) {
                throw new RuntimeException('Ya tienes '.self::MAX_ACTIVE.' descargas ZIP activas. Descarga o elimina alguna antes de pedir otra.');
            }
            // Lasts longer than the download window so cleanup, not expiry, ends it.
            $this->reservations->reserveTemporary($user, $pool, $estimate, $key, (int) config('nas.export_ttl_hours') * 60 + 120);
            $export = new FileExport;
            $export->id = $key;
            $export->user_id = $user->id;
            $export->state = 'queued';
            $export->name = $name;
            $export->location_id = $location;
            $export->root_hash = hash('sha256', $root);
            $export->items = array_map(fn (array $item): array => ['upload' => $item['upload'], 'path' => $item['path']], $items);
            $export->item_count = count($items);
            $export->estimated_bytes = $estimate;
            $export->save();

            return $export;
        });
        if ($estimate <= (int) config('nas.export_sync_max_bytes')) {
            $this->build($export->id);
        }

        return $export->refresh();
    }

    /** Builds one queued export; safe to call from the request and from the scheduler. */
    public function build(string $id): void
    {
        // Atomic claim: only one process ever builds a given export.
        if (FileExport::whereKey($id)->where('state', 'queued')->update(['state' => 'building', 'updated_at' => now()]) !== 1) {
            return;
        }
        $export = FileExport::findOrFail($id);
        $user = User::findOrFail($export->user_id);
        @set_time_limit(0);
        try {
            [$part, $final] = $this->paths($export);
            if (file_exists($part) || is_link($part) || file_exists($final) || is_link($final)) {
                throw new RuntimeException('Quedan restos de un intento anterior.');
            }
            $zip = new ZipArchive;
            if ($zip->open($part, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
                throw new RuntimeException('No se pudo crear el ZIP.');
            }
            foreach ($export->items as $item) {
                $source = $this->uploads->readablePath($user, $item['upload']);
                if (! $zip->addFile($source, $item['path'], 0, 0, ZipArchive::FL_ENC_UTF_8) || ! $zip->setCompressionName($item['path'], ZipArchive::CM_STORE)) {
                    throw new RuntimeException('No se pudo añadir un archivo al ZIP.');
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('No se pudo terminar el ZIP (¿disco lleno?).');
            }
            clearstatcache(true, $part);
            $size = (int) filesize($part);
            if ($size < 1 || $size > $export->estimated_bytes) {
                throw new RuntimeException('El ZIP ocupa más de lo reservado.');
            }
            if (! chmod($part, 0600) || ! rename($part, $final)) {
                throw new RuntimeException('No se pudo publicar el ZIP.');
            }
            $export->state = 'ready';
            $export->bytes = $size;
            $export->expires_at = now()->addHours(max(1, (int) config('nas.export_ttl_hours')));
            $export->save();
        } catch (Throwable $exception) {
            $export->state = 'failed';
            $export->error = $exception instanceof RuntimeException
                ? Str::limit($exception->getMessage(), 250)
                : 'Error inesperado al generar el ZIP.';
            // Visible to the person for a while, then cleaned by run().
            $export->expires_at = now()->addHours(max(1, (int) config('nas.export_ttl_hours')));
            $export->save();
            try {
                $this->cleanupOne($export, 'failed'); // Deletes leftovers, then releases the space.
            } catch (Throwable) {
                // Volume unavailable: space stays reserved and run() retries.
            }
        }
    }

    /** @return string Absolute path of a ready archive owned by the user. */
    public function downloadPath(User $user, string $id): string
    {
        $export = FileExport::where('user_id', $user->id)->findOrFail($id);
        if ($export->state !== 'ready' || $export->expires_at === null || $export->expires_at->isPast()) {
            throw new RuntimeException('Esta descarga ya no está disponible.');
        }
        [, $final] = $this->paths($export);
        clearstatcache(true, $final);
        if (is_link($final) || ! is_file($final)) {
            throw new RuntimeException('El ZIP no está en el disco.');
        }

        return $final;
    }

    public function discard(User $user, string $id): void
    {
        $export = FileExport::where('user_id', $user->id)->findOrFail($id);
        if (in_array($export->state, ['queued', 'building'], true)) {
            throw new RuntimeException('Espera a que termine de prepararse para eliminarlo.');
        }
        $this->cleanupOne($export, 'cleaned');
    }

    /** @return Collection<int, FileExport> */
    public function forUser(User $user): Collection
    {
        return FileExport::where('user_id', $user->id)->where('state', '!=', 'cleaned')->orderByDesc('created_at')->get();
    }

    /**
     * Scheduler entry point: release expired or failed exports, recover builds that
     * died with their process, then build what is queued. Returns failures.
     */
    public function run(): int
    {
        $failures = 0;
        FileExport::where('state', 'building')->where('updated_at', '<', now()->subHours(6))
            ->update(['state' => 'failed', 'error' => 'La preparación se interrumpió.', 'updated_at' => now()]);
        // Failed exports are retried until their space is released; expired ones disappear.
        $stale = FileExport::where(fn ($query) => $query->where('state', 'failed')
            ->orWhere(fn ($ready) => $ready->where('state', 'ready')->where('expires_at', '<', now())))->get();
        foreach ($stale as $export) {
            try {
                $expired = $export->expires_at === null || $export->expires_at->isPast();
                // Only failed exports reach this point unexpired (ready ones are selected expired).
                $this->cleanupOne($export, $expired ? 'cleaned' : 'failed');
            } catch (Throwable) {
                $failures++;
            }
        }
        foreach (FileExport::where('state', 'queued')->orderBy('created_at')->pluck('id') as $id) {
            $this->build((string) $id);
            $failures += FileExport::whereKey($id)->where('state', 'failed')->exists() ? 1 : 0;
        }

        return $failures;
    }

    /**
     * Deletes the archive and partial files, verifies, then releases the reservation
     * (idempotent) and moves the export to $state.
     *
     * @param  'failed'|'cleaned'  $state
     */
    private function cleanupOne(FileExport $export, string $state): void
    {
        [$part, $final] = $this->paths($export);
        foreach ([$part, $final] as $path) {
            if (is_link($path) || (file_exists($path) && ! unlink($path))) {
                throw new RuntimeException('No se pudo borrar el ZIP temporal; el espacio sigue reservado.');
            }
        }
        clearstatcache();
        if (file_exists($part) || file_exists($final)) {
            throw new RuntimeException('No se pudo confirmar el borrado del ZIP temporal.');
        }
        $this->reservations->release(User::findOrFail($export->user_id), $export->id);
        $export->state = $state;
        $export->save();
    }

    /** @return array{string, string} Partial and final paths, after re-checking the area identity. */
    private function paths(FileExport $export): array
    {
        [$root, , $location] = $this->uploads->privateArea();
        if ($export->location_id !== $location || $export->root_hash !== hash('sha256', $root)) {
            throw new RuntimeException('La ubicación privada ha cambiado: requiere reconciliación.');
        }

        return [$root.'/'.$export->id.'.zip.part', $root.'/'.$export->id.'.zip'];
    }

    /**
     * Stored entries: local header + central directory + ZIP64 extras per file, plus the
     * end records and a small margin. Sizes are exact because nothing is compressed.
     *
     * @param  list<array{upload: string, path: string, size: int}>  $items
     */
    private function estimate(array $items): int
    {
        $bytes = 4096;
        foreach ($items as $item) {
            $bytes += $item['size'] + 256 + 2 * strlen($item['path']);
        }

        return $bytes;
    }

    /** @return array{folders: array<int, Folder>, children: array<int, list<int>>} */
    private function tree(User $user): array
    {
        $folders = [];
        $children = [];
        foreach (Folder::where('user_id', $user->id)->orderBy('name')->get() as $folder) {
            $folders[$folder->id] = $folder;
            $children[$folder->parent_id ?? 0][] = $folder->id;
        }

        return ['folders' => $folders, 'children' => $children];
    }

    /**
     * Current files (never trash or older versions) with ZIP paths relative to $base.
     * Names are labels without separators; clashes inside a directory get " (2)".
     *
     * @param  array{folders: array<int, Folder>, children: array<int, list<int>>}  $tree
     * @param  list<int>  $fileIds
     * @param  list<int>  $folderIds
     * @return list<array{upload: string, path: string, size: int}>
     */
    private function collect(User $user, array $tree, ?Folder $base, array $fileIds, array $folderIds, bool $everything): array
    {
        $baseId = $base?->id;
        $byFolder = [];
        foreach ($this->files->currentFiles($user) as $file) {
            $byFolder[$file->folder_id ?? 0][] = $file;
        }
        $items = [];
        $used = [];
        $unique = function (string $dir, string $name) use (&$used): string {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $stem = $extension === '' ? $name : substr($name, 0, -strlen($extension) - 1);
            $candidate = $name;
            for ($i = 2; isset($used[$dir][Str::lower($candidate)]); $i++) {
                $candidate = $stem.' ('.$i.')'.($extension === '' ? '' : '.'.$extension);
            }
            $used[$dir][Str::lower($candidate)] = true;

            return $candidate;
        };
        $addFolder = function (int $folderId, string $dir, int $depth) use (&$addFolder, &$items, $tree, $byFolder, $unique): void {
            foreach ($byFolder[$folderId] ?? [] as $file) {
                $items[] = ['upload' => $file->upload_id, 'path' => $dir.$unique($dir, $file->name), 'size' => (int) $file->size];
            }
            if ($depth > PersonalFiles::MAX_DEPTH) {
                return;
            }
            foreach ($tree['children'][$folderId] ?? [] as $childId) {
                $child = $tree['folders'][$childId];
                $addFolder($childId, $dir.$unique($dir, $child->name).'/', $depth + 1);
            }
        };
        if ($everything) {
            $addFolder($baseId ?? 0, '', 0);

            return $items;
        }
        // Selection: only direct children of the current folder that belong to the person.
        foreach ($byFolder[$baseId ?? 0] ?? [] as $file) {
            if (in_array($file->id, $fileIds, true)) {
                $items[] = ['upload' => $file->upload_id, 'path' => $unique('', $file->name), 'size' => (int) $file->size];
            }
        }
        foreach ($tree['children'][$baseId ?? 0] ?? [] as $childId) {
            if (in_array($childId, $folderIds, true)) {
                $addFolder($childId, $unique('', $tree['folders'][$childId]->name).'/', 1);
            }
        }

        return $items;
    }
}
