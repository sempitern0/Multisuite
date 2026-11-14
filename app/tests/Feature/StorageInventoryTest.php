<?php

namespace Tests\Feature;

use App\Services\Storage\StorageInventory;
use App\Services\Storage\VolumeProbe;
use InvalidArgumentException;
use Mockery\MockInterface;
use Tests\TestCase;

class StorageInventoryTest extends TestCase
{
    /** @param array<string, array{status: string, device: ?string, total: ?int, free: ?int}> $observations */
    private function configure(array $observations, bool $shared = true): void
    {
        $volumes = [];
        foreach ($observations as $id => $result) {
            $volumes[] = ['id' => $id, 'group' => $shared ? 'pool' : $id, 'path' => '/nas/'.$id, 'reserve_bytes' => 100];
        }
        config(['nas.volumes' => json_encode($volumes)]);
        $this->mock(VolumeProbe::class, function (MockInterface $mock) use ($observations): void {
            foreach ($observations as $id => $result) {
                $mock->shouldReceive('inspect')->with('/nas/'.$id, $id)->andReturn($result);
            }
        });
    }

    public function test_shared_folders_do_not_multiply_capacity(): void
    {
        $this->configure([
            'alice' => ['status' => 'observed', 'device' => '1', 'total' => 1000, 'free' => 500],
            'bob' => ['status' => 'observed', 'device' => '1', 'total' => 1000, 'free' => 480],
        ]);
        $report = app(StorageInventory::class)->inspect();
        $this->assertCount(1, $report['capacity_groups']);
        $this->assertSame(1000, $report['capacity_groups'][0]['total_bytes']);
        $this->assertSame(380, $report['capacity_groups'][0]['headroom_bytes']);
        $this->assertFalse($report['write_admission_enabled']);
    }

    public function test_misdeclared_shared_filesystem_is_not_treated_as_two_disks(): void
    {
        $this->configure([
            'alice' => ['status' => 'observed', 'device' => '1', 'total' => 1000, 'free' => 500],
            'bob' => ['status' => 'observed', 'device' => '1', 'total' => 1000, 'free' => 500],
        ], false);
        $report = app(StorageInventory::class)->inspect();
        foreach ($report['capacity_groups'] as $group) {
            $this->assertSame('configuration_conflict', $group['status']);
            $this->assertNull($group['headroom_bytes']);
        }
        $this->artisan('portal:storage-status')->assertExitCode(1);
    }

    public function test_independent_volumes_and_exhausted_reserve(): void
    {
        $this->configure([
            'first' => ['status' => 'observed', 'device' => '1', 'total' => 1000, 'free' => 50],
            'second' => ['status' => 'observed', 'device' => '2', 'total' => 2000, 'free' => 1000],
        ], false);
        $groups = app(StorageInventory::class)->inspect()['capacity_groups'];
        $this->assertSame(0, $groups[0]['headroom_bytes']);
        $this->assertSame(900, $groups[1]['headroom_bytes']);
    }

    public function test_missing_volume_invalidates_the_pool(): void
    {
        $this->configure(['missing' => ['status' => 'unavailable', 'device' => null, 'total' => null, 'free' => null]]);
        $group = app(StorageInventory::class)->inspect()['capacity_groups'][0];
        $this->assertSame('unavailable', $group['status']);
        $this->assertNull($group['headroom_bytes']);
    }

    public function test_invalid_configuration_does_not_break_application_boot(): void
    {
        config(['nas.volumes' => '{invalid']);
        $this->artisan('portal:storage-status')->assertExitCode(1);
        $this->get(route('login'))->assertOk();
        config(['nas.volumes' => json_encode([['id' => 'bad', 'group' => 'pool', 'path' => '../relative', 'reserve_bytes' => -1]])]);
        $this->expectException(InvalidArgumentException::class);
        app(StorageInventory::class)->inspect();
    }

    public function test_real_probe_requires_matching_marker_and_does_not_create_it(): void
    {
        $root = sys_get_temp_dir().'/multisuite-probe-'.bin2hex(random_bytes(6));
        mkdir($root);
        $marker = $root.'/.multisuite-volume-id';
        try {
            $probe = new VolumeProbe;
            $this->assertSame('unavailable', $probe->inspect($root, 'test')['status']);
            $this->assertFileDoesNotExist($marker);
            file_put_contents($marker, 'wrong');
            $this->assertSame('unavailable', $probe->inspect($root, 'test')['status']);
            file_put_contents($marker, 'test');
            $this->assertSame(PHP_OS_FAMILY === 'Linux' ? 'observed' : 'unavailable', $probe->inspect($root, 'test')['status']);
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            rmdir($root);
        }
    }
}
