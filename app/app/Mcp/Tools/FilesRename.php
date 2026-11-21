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

#[Name('files_rename')]
#[Title('Cambiar el nombre de un archivo')]
#[Description('Cambia el nombre de un archivo de Mis archivos de la persona. Necesita su aprobación antes de hacerse.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class FilesRename extends ChangeTool
{
    public function __construct(private PersonalFiles $files) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_id' => $schema->integer()->min(1)->required()->description('Archivo (id de files_list o files_search).'),
            'name' => $schema->string()->min(1)->max(200)->required()->description('Nombre nuevo, con su extensión.'),
        ];
    }

    protected function change(User $user, Request $request): ResponseFactory
    {
        $input = $request->validate([
            'file_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:200'],
        ]);
        $before = $this->files->file($user, (int) $input['file_id'])->name;
        $file = $this->files->renameFile($user, (int) $input['file_id'], (string) $input['name']);

        return Response::structured(['done' => true, 'file' => ['id' => $file->id, 'name' => $file->name], 'previous_name' => $before]);
    }
}
