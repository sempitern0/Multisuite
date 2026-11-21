<?php

namespace App\Actions\Finance;

use App\Models\Folder;
use App\Models\User;
use App\Services\Files\PersonalFiles;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The "Facturas" folder of Mis archivos where tickets uploaded from Mis finanzas go. Found by
 * name (any case) at the top level, created the first time it is needed. Uploads still go
 * through Mis archivos (quota, type check, encryption).
 */
class ReceiptsFolder
{
    public const NAME = 'Facturas';

    public function __construct(private PersonalFiles $files) {}

    public function for(User $user): Folder
    {
        return $this->find($user) ?? $this->create($user);
    }

    private function find(User $user): ?Folder
    {
        return Folder::where('user_id', $user->id)->whereNull('parent_id')
            ->whereRaw('LOWER(name) = ?', [Str::lower(self::NAME)])->orderBy('id')->first();
    }

    private function create(User $user): Folder
    {
        try {
            return $this->files->createFolder($user, null, self::NAME);
        } catch (RuntimeException $exception) {
            // Created meanwhile by another request: use that one.
            return $this->find($user) ?? throw $exception;
        }
    }
}
