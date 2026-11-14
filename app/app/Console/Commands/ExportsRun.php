<?php

namespace App\Console\Commands;

use App\Services\Files\FileExports;
use Illuminate\Console\Command;

class ExportsRun extends Command
{
    protected $signature = 'portal:exports-run';

    protected $description = 'Genera las descargas ZIP en cola y borra las caducadas liberando su espacio temporal';

    public function handle(FileExports $exports): int
    {
        $failures = $exports->run();
        if ($failures > 0) {
            $this->error("{$failures} descargas ZIP fallaron o no se pudieron limpiar; se reintentará.");

            return self::FAILURE;
        }
        $this->info('Descargas ZIP al día.');

        return self::SUCCESS;
    }
}
