<?php

namespace App\Mcp\Tools;

use App\Models\User;
use App\Services\Files\PersonalFiles;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('files_folder_create')]
#[Title('Crear una carpeta')]
#[Description('Crea una carpeta en Mis archivos de la persona, en la carpeta principal o dentro de parent_folder_id. Necesita su aprobación antes de hacerse.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class FolderCreate extends ChangeTool
{
    public function __construct(private PersonalFiles $files) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->min(1)->max(120)->required()->description('Nombre de la carpeta nueva.'),
            'parent_folder_id' => $schema->integer()->min(1)->description('Carpeta donde crearla (id de files_list). Vacío para la carpeta principal.'),
        ];
    }

    protected function change(User $user, Request $request): ResponseFactory
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'parent_folder_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $parent = $this->files->folder($user, isset($input['parent_folder_id']) ? (int) $input['parent_folder_id'] : null);
        $folder = $this->files->createFolder($user, $parent?->id, (string) $input['name']);

        return Response::structured(['done' => true, 'folder' => ['id' => $folder->id, 'name' => $folder->name], 'inside' => $parent?->name ?? 'Mis archivos']);
    }
}
