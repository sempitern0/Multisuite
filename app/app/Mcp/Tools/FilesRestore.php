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

#[Name('files_restore')]
#[Title('Sacar un archivo de la papelera')]
#[Description('Devuelve un archivo de la papelera de la persona a su carpeta (o a la principal si ya no existe). Usa el id de files_trash_list. Necesita su aprobación antes de hacerse.')]
#[IsReadOnly(false)]
#[IsDestructive(false)]
class FilesRestore extends ChangeTool
{
    public function __construct(private PersonalFiles $files) {}

    public function schema(JsonSchema $schema): array
    {
        return ['file_id' => $schema->integer()->min(1)->required()->description('Archivo de la papelera (id de files_trash_list).')];
    }

    protected function change(User $user, Request $request): ResponseFactory
    {
        $input = $request->validate(['file_id' => ['required', 'integer', 'min:1']]);
        $file = $this->files->restore($user, (int) $input['file_id']);
        $paths = $this->files->folderPaths($user);

        return Response::structured(['done' => true, 'file' => ['id' => $file->id, 'name' => $file->name], 'folder' => $file->folder_id === null ? 'Mis archivos' : ($paths[(int) $file->folder_id] ?? null)]);
    }
}
