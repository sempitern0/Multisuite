<?php

namespace App\Console\Commands;

use App\Services\Files\PersonalFiles;
use Illuminate\Console\Command;

class FilesPurge extends Command
{
    protected $signature = 'portal:files-purge';

    protected $description = 'Borra definitivamente la papelera caducada y las versiones que superan el máximo';

    public function handle(PersonalFiles $files): int
    {
        [$purged, $failed] = $files->enforceRetention((int) config('nas.trash_days'), (int) config('nas.max_versions'));
        $this->info("Archivos borrados definitivamente: {$purged}.");
        if ($failed > 0) {
            $this->error("{$failed} no se pudieron borrar (volumen no disponible o permisos); siguen contando en la cuota y se reintentará.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
