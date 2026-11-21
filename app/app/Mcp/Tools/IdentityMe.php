<?php

namespace App\Mcp\Tools;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('identity_me')]
#[Title('Tu cuenta')]
#[Description('Nombre, usuario y rol en Multisuite de la persona que usa el asistente.')]
#[IsReadOnly]
#[IsIdempotent]
class IdentityMe extends AssistantTool
{
    protected function run(User $user, Request $request): ResponseFactory
    {
        // No email or other contact data: only what the assistant needs to address the person.
        return Response::structured([
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'member_since' => $user->created_at?->toDateString(),
        ]);
    }
}
