<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\ServiceTiles;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('services_list')]
#[Title('Tus servicios')]
#[Description('Servicios de Multisuite que la persona tiene (música, lectura…) y si están listos para abrirse.')]
#[IsReadOnly]
#[IsIdempotent]
class ServicesList extends AssistantTool
{
    protected function run(User $user, Request $request): ResponseFactory
    {
        // Same decision as the home page; addresses are left out (they are opened from the portal).
        $services = array_map(fn (array $tile): array => [
            'id' => $tile['id'],
            'name' => $tile['name'],
            'status' => $tile['status'],
            'label' => $tile['label'],
            'hint' => $tile['hint'],
        ], ServiceTiles::forUser($user));

        return Response::structured(['services' => $services]);
    }
}
