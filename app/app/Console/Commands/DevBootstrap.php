<?php

namespace App\Console\Commands;

use App\Models\StoragePool;
use App\Models\StorageSetting;
use App\Services\Storage\StorageInventory;
use App\Services\Storage\StorageReservations;
use App\Support\Bytes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Development convenience run by `make up`: wait for the database, migrate and
 * fill storage limits that nobody has chosen yet. It never overwrites a limit set
 * in the GUI and refuses to run in production.
 */
class DevBootstrap extends Command
{
    protected $signature = 'portal:dev-bootstrap {--wait=60 : Segundos máximos esperando a la base de datos}';

    protected $description = 'Prepara el entorno de desarrollo: migraciones y límites de almacenamiento sin configurar';

    public function handle(StorageInventory $inventory, StorageReservations $reservations): int
    {
        if (app()->isProduction()) {
            $this->warn('portal:dev-bootstrap no se ejecuta en producción.');

            return self::SUCCESS;
        }
        if (! $this->waitForDatabase(max(0, (int) $this->option('wait')))) {
            $this->error('La base de datos no responde. Revisa `make ps` y vuelve a ejecutar `make up`.');

            return self::FAILURE;
        }
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Las migraciones han fallado: el entorno no está listo. Revisa el error anterior.');

            return self::FAILURE;
        }

        try {
            $report = $inventory->inspect();
        } catch (InvalidArgumentException $exception) {
            $this->error('NAS_STORAGE_VOLUMES no es válido: '.$exception->getMessage());

            return self::FAILURE;
        }
        // Make the effective storage visible: container env (dev overlay) wins over app/.env.
        $locations = array_map(
            fn (array $location): string => $location['id'].' (grupo '.$location['group'].')',
            array_filter((array) $report['locations'], 'is_array'),
        );
        $this->line('Ubicaciones de almacenamiento: '.($locations === [] ? 'ninguna configurada' : implode(', ', $locations)).'.');
        $groups = (array) $report['capacity_groups'];
        foreach ($groups as $group) {
            if (! is_array($group) || ! is_string($group['id'] ?? null)) {
                continue;
            }
            if (StoragePool::whereKey($group['id'])->exists()) {
                $this->line('Disco '.$group['id'].': límite ya configurado, se respeta.');

                continue;
            }
            if (($group['status'] ?? null) !== 'observed' || ! is_int($group['headroom_bytes'] ?? null)) {
                $this->warn('Disco '.$group['id'].': no disponible, sin cambios.');

                continue;
            }
            try {
                $reservations->setPoolLimit($group['id'], min($group['headroom_bytes'], StorageReservations::MAX_BYTES));
                $this->info('Disco '.$group['id'].': límite inicial '.Bytes::format($group['headroom_bytes']).' (todo lo disponible).');
            } catch (RuntimeException $exception) {
                $this->warn('Disco '.$group['id'].': '.$exception->getMessage());
            }
        }

        $default = (int) config('nas.bootstrap_default_quota_bytes', 0);
        // Only when nobody has ever chosen a default: an explicit 0 ("Sin espacio") is respected.
        if ($default > 0 && ! StorageSetting::whereKey(StorageSetting::SINGLETON)->exists()) {
            $reservations->setDefaultUserQuota(min($default, StorageReservations::MAX_BYTES));
            $this->info('Espacio por defecto por persona: '.Bytes::format($default).'.');
        }
        $this->info('Entorno de desarrollo listo.');

        return self::SUCCESS;
    }

    private function waitForDatabase(int $seconds): bool
    {
        $deadline = time() + $seconds;
        do {
            try {
                DB::connection()->getPdo();

                return true;
            } catch (Throwable) {
                sleep(2);
            }
        } while (time() < $deadline);

        return false;
    }
}
