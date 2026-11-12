<?php

namespace Tests\Integration;

use App\Models\StoragePool;
use App\Models\User;
use App\Models\UserStorageQuota;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorageConcurrencyTest extends TestCase
{
    public function test_postgres_serializes_competing_reservations(): void
    {
        if (getenv('STORAGE_CONCURRENCY_TEST') !== '1') {
            $this->markTestSkipped('Disposable PostgreSQL required.');
        }
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('multisuite_storage_ci', config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $pool = new StoragePool;
        $pool->id = 'disk';
        $pool->limit_bytes = 1000;
        $pool->save();
        $directory = sys_get_temp_dir().'/storage-race-'.bin2hex(random_bytes(8));
        mkdir($directory);
        $processes = [];
        $streams = [];
        try {
            for ($index = 0; $index < 2; $index++) {
                $user = User::factory()->create();
                $quota = new UserStorageQuota;
                $quota->user_id = $user->id;
                $quota->limit_bytes = 1000;
                $quota->save();
                $process = proc_open([PHP_BINARY, base_path('tests/Support/storage_reserve_worker.php'), (string) $user->id,
                    (string) Str::uuid(), $directory.'/ready-'.$index, $directory.'/go'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = $process;
                $streams[] = [$pipes[1], $pipes[2]];
            }
            $deadline = microtime(true) + 10;
            while (! is_file($directory.'/ready-0') || ! is_file($directory.'/ready-1')) {
                $this->assertLessThan($deadline, microtime(true), 'Workers did not reach barrier.');
                usleep(10000);
            }
            touch($directory.'/go');
            $results = [];
            foreach ($processes as $index => $process) {
                $results[] = stream_get_contents($streams[$index][0]);
                $errors = stream_get_contents($streams[$index][1]);
                fclose($streams[$index][0]);
                fclose($streams[$index][1]);
                $this->assertSame(0, proc_close($process), $errors);
            }
            $processes = [];
            sort($results);
            $this->assertSame(['rejected', 'reserved'], $results);
            $this->assertSame(700, $pool->fresh()->reserved_bytes);
            $this->assertSame(700, (int) UserStorageQuota::sum('reserved_bytes'));
            $this->assertDatabaseCount('storage_reservations', 1);
        } finally {
            foreach ($processes as $process) {
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            foreach (glob($directory.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
