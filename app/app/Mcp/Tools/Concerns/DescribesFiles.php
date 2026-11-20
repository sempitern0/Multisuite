<?php

namespace App\Mcp\Tools\Concerns;

use App\Enums\FileKind;
use App\Models\Folder;
use App\Models\UserFile;
use App\Services\Files\ConnectedFolders;
use App\Support\Bytes;
use Illuminate\Support\Collection;

/**
 * Metadata the assistant may see about Mis archivos: names, kinds, sizes and dates.
 * Never contents, blob paths, upload identifiers or anything about other people.
 */
trait DescribesFiles
{
    /**
     * @param  array{folders: Collection<int, Folder>, files: Collection<int, UserFile>, more: bool}  $page
     * @param  array<int, string>  $paths
     * @return array<string, mixed>
     */
    protected function describePage(array $page, int $number, array $paths): array
    {
        return [
            'folders' => $page['folders']->map(fn (Folder $folder): array => [
                'id' => $folder->id,
                'name' => $folder->name,
                'path' => $paths[$folder->id] ?? $folder->name,
                'connected_to' => $folder->isConnected()
                    ? config('portal.services.'.ConnectedFolders::definition((string) $folder->media_link)['service'].'.name')
                    : null,
            ])->values()->all(),
            'files' => $page['files']->map(fn (UserFile $file): array => [
                'id' => $file->id,
                'name' => $file->name,
                'folder' => $file->folder_id === null ? 'Mis archivos' : ($paths[(int) $file->folder_id] ?? null),
                'kind' => FileKind::fromMime($file->mime)->label(),
                'size_bytes' => (int) $file->size,
                'size' => Bytes::format((int) $file->size),
                'uploaded_at' => $file->created_at?->toDateString(),
            ])->values()->all(),
            'page' => $number,
            'next_page' => $page['more'] ? $number + 1 : null,
        ];
    }
}
