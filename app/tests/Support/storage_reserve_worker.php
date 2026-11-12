<?php

use App\Models\User;
use App\Services\Storage\StorageInventory;
use App\Services\Storage\StorageReservations;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (getenv('STORAGE_CONCURRENCY_TEST') !== '1' || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'multisuite_storage_ci') {
    exit(2);
}
$app->instance(StorageInventory::class, new class extends StorageInventory
{
    public function __construct() {}

    public function inspect(): array
    {
        // Extend the critical section so the competing transaction overlaps it.
        usleep(300000);

        return ['capacity_groups' => [['id' => 'disk', 'status' => 'observed', 'headroom_bytes' => 1000]]];
    }
});
touch($argv[3]);
$deadline = microtime(true) + 15;
while (! is_file($argv[4])) {
    if (microtime(true) > $deadline) {
        exit(3);
    }
    usleep(10000);
}
try {
    $app->make(StorageReservations::class)->reserve(User::findOrFail((int) $argv[1]), 'disk', 700, $argv[2]);
    echo 'reserved';
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Cuota o espacio disponible insuficiente.') {
        exit(4);
    }
    echo 'rejected';
}
