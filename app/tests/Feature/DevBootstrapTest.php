<?php

namespace Tests\Feature;

use App\Models\StoragePool;
use App\Services\Storage\StorageInventory;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class DevBootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(StorageInventory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inspect')->andReturn(['capacity_groups' => [
                ['id' => 'dev-nas', 'status' => 'observed', 'headroom_bytes' => 5000],
                ['id' => 'kept', 'status' => 'observed', 'headroom_bytes' => 9000],
                ['id' => 'gone', 'status' => 'unavailable', 'headroom_bytes' => null],
            ]]);
        });
    }

    public function test_fills_only_unset_limits_and_default_and_is_idempotent(): void
    {
        config(['nas.bootstrap_default_quota_bytes' => 700]);
        app(StorageReservations::class)->setPoolLimit('kept', 123);

        $this->artisan('portal:dev-bootstrap', ['--wait' => 0])->assertSuccessful();
        $this->assertSame(5000, StoragePool::findOrFail('dev-nas')->limit_bytes);
        $this->assertSame(123, StoragePool::findOrFail('kept')->limit_bytes);
        $this->assertNull(StoragePool::find('gone'));
        $this->assertSame(700, app(StorageReservations::class)->defaultUserQuota());

        // A choice made later in the GUI survives the next `make up`.
        app(StorageReservations::class)->setPoolLimit('dev-nas', 10);
        app(StorageReservations::class)->setDefaultUserQuota(0);
        $this->artisan('portal:dev-bootstrap', ['--wait' => 0])->assertSuccessful();
        $this->assertSame(10, StoragePool::findOrFail('dev-nas')->limit_bytes);
        $this->assertSame(0, app(StorageReservations::class)->defaultUserQuota());
    }

    public function test_refuses_to_change_anything_in_production(): void
    {
        config(['nas.bootstrap_default_quota_bytes' => 700]);
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('portal:dev-bootstrap', ['--wait' => 0])->assertSuccessful();
        $this->assertNull(StoragePool::find('dev-nas'));
        $this->assertSame(0, app(StorageReservations::class)->defaultUserQuota());
    }
}
