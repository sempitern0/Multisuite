<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Storage\StorageReservations;
use Illuminate\Console\Command;
use RuntimeException;

class StorageQuota extends Command
{
    protected $signature = 'portal:storage-quota {scope : user o pool} {subject : Correo del usuario o ID del grupo} {bytes : Límite entero en bytes}';

    protected $description = 'Configura límites contables de almacenamiento; no habilita subidas';

    public function handle(StorageReservations $reservations): int
    {
        $raw = (string) $this->argument('bytes');
        if (! preg_match('/^(0|[1-9][0-9]{0,15})$/D', $raw) || (int) $raw > StorageReservations::MAX_BYTES) {
            $this->error('Indica un límite entero en bytes dentro del rango admitido.');

            return self::FAILURE;
        }
        try {
            $subject = (string) $this->argument('subject');
            if ($this->argument('scope') === 'user') {
                $user = User::where('email', $subject)->first();
                if ($user === null) {
                    throw new RuntimeException('Usuario no encontrado.');
                }
                $reservations->setUserQuota($user, (int) $raw);
            } elseif ($this->argument('scope') === 'pool') {
                $reservations->setPoolLimit($subject, (int) $raw);
            } else {
                throw new RuntimeException('Ámbito admitido: user o pool.');
            }
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Límite actualizado. La recepción depende de NAS_UPLOADS_ENABLED y de la ubicación privada configurada.');

        return self::SUCCESS;
    }
}
