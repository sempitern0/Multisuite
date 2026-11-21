<?php

namespace App\Services\Assistant;

use App\Models\Folder;
use App\Models\User;
use App\Models\UserFile;
use Illuminate\Support\Str;

/**
 * Plain-Spanish description of a change the assistant proposes, for the approval card.
 * Targets are looked up among the person's own files and folders; what the model wrote is
 * only used for the new name, shown as such (MCP-04).
 */
class ChangeDescriber
{
    /** @param array<string, mixed> $arguments */
    public function describe(User $user, string $tool, array $arguments): string
    {
        $file = fn (): string => $this->fileName($user, $arguments['file_id'] ?? null);

        return match ($tool) {
            'files_folder_create' => 'Crear la carpeta «'.$this->text($arguments['name'] ?? '').'» en «'.$this->folderName($user, $arguments['parent_folder_id'] ?? null).'».',
            'files_rename' => 'Cambiar el nombre de «'.$file().'» a «'.$this->text($arguments['name'] ?? '').'».',
            'files_trash' => 'Mandar «'.$file().'» a la papelera. Se puede recuperar desde allí.',
            'files_restore' => 'Sacar «'.$file().'» de la papelera.',
            default => 'Un cambio que Multisuite no reconoce: recházalo.',
        };
    }

    private function fileName(User $user, mixed $id): string
    {
        $file = is_numeric($id) ? UserFile::where('user_id', $user->id)->find((int) $id) : null;

        return $file === null ? 'un archivo que no existe o no es tuyo' : $this->text($file->name);
    }

    private function folderName(User $user, mixed $id): string
    {
        if ($id === null || $id === '') {
            return 'Mis archivos';
        }
        $folder = is_numeric($id) ? Folder::where('user_id', $user->id)->find((int) $id) : null;

        return $folder === null ? 'una carpeta que no existe o no es tuya' : $this->text($folder->name);
    }

    private function text(mixed $value): string
    {
        return Str::limit(is_scalar($value) ? trim((string) $value) : '', 120);
    }
}
