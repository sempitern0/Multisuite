<?php

namespace Tests\Concerns;

use App\Models\Folder;
use App\Models\UserFile;
use Livewire\Livewire;

/**
 * Drives the Mis archivos pages (the only entry to file operations) as the signed-in
 * person. A page that refuses to open (someone else's file or folder) is returned as is
 * so the test can assert the refusal; otherwise the action is called.
 */
trait UsesFilePages
{
    protected function createFolder(string $name, ?int $parentId = null)
    {
        return $this->folderPage($parentId, fn ($page) => $page->set('newFolder', $name)->call('createFolder'));
    }

    protected function renameFolder(int $folderId, string $name)
    {
        return $this->folderPage($folderId, fn ($page) => $page->set('folderName', $name)->call('renameFolder'));
    }

    protected function deleteFolder(int $folderId)
    {
        return $this->folderPage($folderId, fn ($page) => $page->call('deleteFolder'));
    }

    /** @param array{scope: string, folder?: ?int, files?: list<int>, folders?: list<int>} $input */
    protected function requestExport(array $input)
    {
        return $this->folderPage($input['folder'] ?? null, fn ($page) => $page
            ->set('selectedFiles', $input['files'] ?? [])
            ->set('selectedFolders', $input['folders'] ?? [])
            ->call('export', $input['scope']));
    }

    protected function renameFile(int $fileId, string $name)
    {
        return $this->filePage($fileId, fn ($page) => $page->set('name', $name)->call('rename'));
    }

    protected function trashFile(int $fileId)
    {
        return $this->filePage($fileId, fn ($page) => $page->call('trash'));
    }

    protected function restoreFile(int $fileId)
    {
        return $this->filePage($fileId, fn ($page) => $page->call('restore'));
    }

    protected function promoteFile(int $fileId)
    {
        return $this->filePage($fileId, fn ($page) => $page->call('promote'));
    }

    protected function purgeFile(int $fileId)
    {
        return $this->filePage($fileId, fn ($page) => $page->call('purge'));
    }

    protected function emptyTrash()
    {
        return Livewire::test('pages::files.trash')->call('emptyTrash');
    }

    protected function discardExport(string $exportId)
    {
        return Livewire::test('pages::files.exports')->call('discard', $exportId);
    }

    private function folderPage(?int $folderId, callable $act)
    {
        $page = Livewire::test('pages::files.index', ['folderId' => $folderId]);
        $mine = $folderId === null || Folder::where('user_id', auth()->id())->whereKey($folderId)->exists();

        return $mine ? $act($page) : $page;
    }

    private function filePage(int $fileId, callable $act)
    {
        $page = Livewire::test('pages::files.show', ['fileId' => $fileId]);

        return UserFile::where('user_id', auth()->id())->whereKey($fileId)->exists() ? $act($page) : $page;
    }
}
