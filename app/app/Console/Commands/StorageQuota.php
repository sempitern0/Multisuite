<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Storage\StorageReservations;
use App\Support\Bytes;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class StorageQuota extends Command
{
    protected $signature = 'portal:storage-quota {scope : user o pool} {subject : Correo del usuario o ID del grupo} {bytes : Límite en bytes o con unidad: 50GB, 1,5TB, 500MB}';

    protected $description = 'Configura límites contables de almacenamiento; no habilita subidas';

    public function handle(StorageReservations $reservations): int
    {
        $raw = trim((string) $this->argument('bytes'));
        try {
            if (preg_match('/^(\d[\d.,]*)\s*(MB|GB|TB)$/Di', $raw, $matches)) {
                $raw = (string) Bytes::fromInput($matches[1], strtoupper($matches[2]));
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        if (! preg_match('/^(0|[1-9][0-9]{0,15})$/D', $raw) || (int) $raw > StorageReservations::MAX_BYTES) {
            $this->error('Indica un límite en bytes o con unidad (50GB, 1,5TB) dentro del rango admitido.');

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
        $this->info('Límite actualizado a '.Bytes::format((int) $raw).'. La recepción depende de NAS_UPLOADS_ENABLED y de la ubicación privada configurada.');

        return self::SUCCESS;
    }
}
