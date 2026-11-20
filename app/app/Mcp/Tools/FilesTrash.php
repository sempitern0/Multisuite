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

#[Name('files_trash')]
#[Title('Mandar un archivo a la papelera')]
#[Description('Manda un archivo de Mis archivos de la persona a la papelera, de donde puede recuperarse. Nunca lo borra definitivamente. Necesita su aprobación antes de hacerse.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class FilesTrash extends ChangeTool
{
    public function __construct(private PersonalFiles $files) {}

    public function schema(JsonSchema $schema): array
    {
        return ['file_id' => $schema->integer()->min(1)->required()->description('Archivo (id de files_list o files_search).')];
    }

    protected function change(User $user, Request $request): ResponseFactory
    {
        $input = $request->validate(['file_id' => ['required', 'integer', 'min:1']]);
        $file = $this->files->trash($user, (int) $input['file_id']);

        return Response::structured(['done' => true, 'file' => ['id' => $file->id, 'name' => $file->name], 'where' => 'Papelera', 'recoverable' => true]);
    }
}
