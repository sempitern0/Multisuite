<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Storage\BackupStatus;
use App\Services\Storage\StorageInventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupStatusTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/backup-status-'.Str::uuid().'.json';
        config(['nas.backup_status' => $this->path]);
        $this->travelTo('2026-11-14 12:00:00');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function state(array|string $status): ?string
    {
        file_put_contents($this->path, is_string($status) ? $status : json_encode($status + ['schema_version' => 1, 'started_at' => '2026-11-14T03:00:00Z']));

        return app(BackupStatus::class)->read()['state'] ?? null;
    }

    public function test_distinguishes_recent_stale_failed_running_and_unknown_backups(): void
    {
        $this->assertNull(app(BackupStatus::class)->read());
        $this->assertNull($this->state('{}'));
        $this->assertNull($this->state('{not json'));
        $this->assertSame('ok', $this->state(['ok' => true, 'finished_at' => '2026-11-14T03:20:00Z']));
        $this->assertSame('stale', $this->state(['ok' => true, 'finished_at' => '2026-11-11T03:20:00Z']));
        $this->assertSame('failed', $this->state(['ok' => false, 'finished_at' => '2026-11-14T03:05:00Z', 'step' => 'check']));
        $this->assertSame('failed', $this->state(['ok' => true, 'finished_at' => '<script>']));
        $this->assertSame('running', $this->state(['ok' => null, 'finished_at' => '']));
    }

    public function test_storage_page_warns_the_administrator_about_a_failed_backup(): void
    {
        $this->mock(StorageInventory::class, fn (MockInterface $mock) => $mock->shouldReceive('inspect')->andReturn(['locations' => [], 'capacity_groups' => []]));
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $page = fn () => $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])->get('/admin/storage')->assertOk();

        $page()->assertSee('Sin copia externa');
        $this->state(['ok' => false, 'finished_at' => '2026-11-14T03:05:00Z', 'step' => 'check']);
        $page()->assertSee('La última copia falló')->assertDontSee('Sin copia externa');
    }
}
