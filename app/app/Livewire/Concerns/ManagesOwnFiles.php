<?php

namespace App\Livewire\Concerns;

use App\Models\UserFile;
use App\Services\Files\PersonalFiles;
use App\Support\DomainErrors;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * File operations offered from more than one page (explorer, preview, trash). Each one
 * applies the shared request budget, looks the file up scoped to its owner (someone
 * else's file is "not found"), checks the policy and turns a domain refusal into a
 * message under a stable field. Pages only decide what happens on screen afterwards.
 */
trait ManagesOwnFiles
{
    abstract protected function throttle(string $limiterName): void;

    protected function trashOwnFile(int $fileId): UserFile
    {
        $this->authorizeOwnFile($fileId);

        return DomainErrors::guard('file_trash', fn () => app(PersonalFiles::class)->trash(Auth::user(), $fileId));
    }

    protected function restoreOwnFile(int $fileId): UserFile
    {
        $this->authorizeOwnFile($fileId);

        return DomainErrors::guard('file_trash', fn () => app(PersonalFiles::class)->restore(Auth::user(), $fileId));
    }

    protected function promoteOwnFile(int $versionId): UserFile
    {
        $this->authorizeOwnFile($versionId);

        return DomainErrors::guard('file_version', fn () => app(PersonalFiles::class)->promote(Auth::user(), $versionId));
    }

    /** @return int|null id of the current file when the purged one was a version */
    protected function purgeOwnFile(int $fileId): ?int
    {
        $current = $this->authorizeOwnFile($fileId)->version_of_id;
        DomainErrors::guard('file_purge', fn () => app(PersonalFiles::class)->purge(Auth::user(), $fileId));

        return $current;
    }

    protected function trashedMessage(UserFile $file): string
    {
        return '«'.$file->name.'» está en la papelera. Puedes restaurarlo durante '.(int) config('nas.trash_days').' días';
    }

    private function authorizeOwnFile(int $fileId): UserFile
    {
        $this->throttle('files');
        $file = app(PersonalFiles::class)->file(Auth::user(), $fileId);
        Gate::authorize('update', $file);

        return $file;
    }
}
