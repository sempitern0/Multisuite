<?php

namespace App\Console\Commands;

use App\Services\Storage\StorageInventory;
use Illuminate\Console\Command;
use InvalidArgumentException;

class StorageStatus extends Command
{
    protected $signature = 'portal:storage-status';

    protected $description = 'Inventario NAS de solo lectura, sin habilitar escrituras ni cuotas';

    public function handle(StorageInventory $inventory): int
    {
        try {
            $report = $inventory->inspect();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        foreach ($report['capacity_groups'] as $group) {
            if ($group['status'] !== 'observed') {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
