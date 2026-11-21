<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Files\PersonalFiles;
use App\Support\Bytes;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('storage_my_usage')]
#[Title('Tu espacio')]
#[Description('Espacio usado, límite y espacio en la papelera de Mis archivos de la persona.')]
#[IsReadOnly]
#[IsIdempotent]
class StorageMyUsage extends AssistantTool
{
    public function __construct(private PersonalFiles $files) {}

    protected function run(User $user, Request $request): ResponseFactory
    {
        $quota = UserStorageQuota::summaryFor($user->id);
        $trash = $this->files->trashBytes($user);

        // Sizes only: never disks, mount points or other people's usage.
        return Response::structured([
            'used_bytes' => $quota['used'],
            'limit_bytes' => $quota['limit'],
            'free_bytes' => max(0, $quota['limit'] - $quota['used']),
            'trash_bytes' => $trash,
            'used' => Bytes::format($quota['used']),
            'limit' => Bytes::format($quota['limit']),
            'trash' => Bytes::format($trash),
        ]);
    }
}
