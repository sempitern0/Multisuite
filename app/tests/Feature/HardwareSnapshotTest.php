<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Storage\HardwareSnapshot;
use App\Services\Storage\StorageInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class HardwareSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/nas-hardware-'.Str::uuid().'.json';
        config(['nas.hardware_snapshot' => $this->path]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function write(mixed $lsblk, array $targets = ['/srv/datos']): void
    {
        file_put_contents($this->path, json_encode(['schema_version' => 1, 'generated_at' => '2026-11-13T20:00:00Z', 'host' => 'nas', 'portal_targets' => $targets, 'lsblk' => $lsblk]));
    }

    public function test_reads_disks_partitions_and_portal_usage_from_lsblk(): void
    {
        $this->write(['blockdevices' => [
            ['name' => 'loop0', 'type' => 'disk', 'size' => 1000],
            ['name' => 'sda', 'type' => 'disk', 'size' => 512110190592, 'model' => 'Samsung SSD ', 'rm' => false, 'children' => [
                ['name' => 'sda1', 'type' => 'part', 'size' => 536870912, 'fstype' => 'vfat', 'mountpoint' => '/boot/efi'],
                ['name' => 'sda2', 'type' => 'part', 'size' => '511573319680', 'fstype' => 'ext4', 'mountpoint' => '/srv/datos', 'fssize' => '503000000000', 'fsavail' => 400000000000],
            ]],
            ['name' => 'sdb', 'type' => 'disk', 'size' => 2000398934016, 'model' => 'WD Red', 'rm' => true, 'fstype' => 'xfs', 'mountpoint' => ''],
            ['name' => 'sdc', 'type' => 'disk', 'size' => 1000204886016, 'model' => 'Nuevo'],
        ]]);

        $snapshot = app(HardwareSnapshot::class)->read();
        $this->assertNotNull($snapshot);
        $this->assertSame(['sda', 'sdb', 'sdc'], array_column($snapshot['disks'], 'name'));
        $this->assertSame('Samsung SSD', $snapshot['disks'][0]['model']);
        $this->assertSame([false, true], array_column($snapshot['disks'][0]['partitions'], 'in_use'));
        $this->assertSame(511573319680, $snapshot['disks'][0]['partitions'][1]['size']);
        $this->assertSame(400000000000, $snapshot['disks'][0]['partitions'][1]['fs_available']);
        $this->assertTrue($snapshot['disks'][1]['removable']);
        $this->assertSame('sdb', $snapshot['disks'][1]['partitions'][0]['name']);
        $this->assertSame([], $snapshot['disks'][2]['partitions']);

        $this->mock(StorageInventory::class, fn (MockInterface $mock) => $mock->shouldReceive('inspect')->andReturn(['locations' => [], 'capacity_groups' => []]));
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/storage')->assertOk()
            ->assertSee('Discos del servidor')->assertSee('Samsung SSD')->assertSee('en uso por el portal')
            ->assertSee('No montada')->assertSee('Sin particiones con sistema de archivos');
    }

    public function test_missing_or_malformed_snapshots_show_nothing(): void
    {
        $this->assertNull(app(HardwareSnapshot::class)->read());
        file_put_contents($this->path, '{not json');
        $this->assertNull(app(HardwareSnapshot::class)->read());
        $this->write('oops');
        $this->assertNull(app(HardwareSnapshot::class)->read());
        config(['nas.hardware_snapshot' => '']);
        $this->assertNull(app(HardwareSnapshot::class)->read());
    }
}
