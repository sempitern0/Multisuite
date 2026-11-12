<?php

namespace Tests\Feature;

use App\Models\StoragePool;
use App\Models\StorageReservation;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Storage\StorageInventory;
use App\Services\Storage\StorageReservations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class StorageReservationsTest extends TestCase
{
    use RefreshDatabase;

    private function service(int $free = 1000): StorageReservations
    {
        $this->mock(StorageInventory::class, function (MockInterface $mock) use ($free): void {
            $mock->shouldReceive('inspect')->andReturn(['capacity_groups' => [
                ['id' => 'disk', 'status' => 'observed', 'headroom_bytes' => $free],
                ['id' => 'other', 'status' => 'observed', 'headroom_bytes' => $free],
            ]]);
        });

        return app(StorageReservations::class);
    }

    public function test_reservation_retry_and_commit_are_idempotent(): void
    {
        $user = User::factory()->create();
        $service = $this->service();
        $service->setPoolLimit('disk', 1000);
        $service->setUserQuota($user, 600);
        $key = (string) Str::uuid();
        $service->reserve($user, 'disk', 500, $key);
        $service->reserve($user, 'disk', 500, $key);
        $this->assertSame(500, StoragePool::findOrFail('disk')->reserved_bytes);
        $service->commit($user, $key, 400);
        $service->commit($user, $key, 400);
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->assertSame(400, UserStorageQuota::findOrFail($user->id)->used_bytes);
        $this->assertSame('committed', $service->reserve($user, 'disk', 500, $key)->state);
        $this->assertDatabaseCount('storage_reservations', 1);
    }

    public function test_shared_pool_capacity_is_reserved_across_users(): void
    {
        $service = $this->service(700);
        $service->setPoolLimit('disk', 2000);
        $a = User::factory()->create();
        $b = User::factory()->create();
        $service->setUserQuota($a, 2000);
        $service->setUserQuota($b, 2000);
        $service->reserve($a, 'disk', 500, (string) Str::uuid());
        try {
            $service->reserve($b, 'disk', 300, (string) Str::uuid());
            $this->fail('Must reject over-allocation.');
        } catch (RuntimeException) {
            $this->assertSame(500, StoragePool::findOrFail('disk')->reserved_bytes);
            $this->assertSame(0, UserStorageQuota::findOrFail($b->id)->reserved_bytes);
        }
    }

    public function test_user_quota_is_shared_across_pools(): void
    {
        $service = $this->service();
        $user = User::factory()->create();
        $service->setPoolLimit('disk', 1000);
        $service->setPoolLimit('other', 1000);
        $service->setUserQuota($user, 600);
        $service->reserve($user, 'disk', 400, (string) Str::uuid());
        $this->expectException(RuntimeException::class);
        $service->reserve($user, 'other', 300, (string) Str::uuid());
    }

    public function test_expiration_does_not_release_capacity_until_cleanup(): void
    {
        $service = $this->service();
        $user = User::factory()->create();
        $service->setPoolLimit('disk', 1000);
        $service->setUserQuota($user, 1000);
        $key = (string) Str::uuid();
        $service->reserve($user, 'disk', 500, $key);
        $this->travel(31)->minutes();
        try {
            $service->commit($user, $key, 400);
            $this->fail('Expired reservations cannot commit.');
        } catch (RuntimeException) {
            $this->assertSame(500, StoragePool::findOrFail('disk')->reserved_bytes);
        }
        $service->release($user, $key);
        $service->release($user, $key);
        $this->assertSame(0, StoragePool::findOrFail('disk')->reserved_bytes);
        $this->assertSame('released', StorageReservation::findOrFail($key)->state);
    }

    public function test_user_cannot_release_another_users_reservation(): void
    {
        $service = $this->service();
        $a = User::factory()->create();
        $b = User::factory()->create();
        $service->setPoolLimit('disk', 1000);
        $service->setUserQuota($a, 1000);
        $key = (string) Str::uuid();
        $service->reserve($a, 'disk', 100, $key);
        $this->expectException(RuntimeException::class);
        $service->release($b, $key);
    }

    public function test_admin_deletion_is_blocked_before_remote_cleanup(): void
    {
        $service = $this->service();
        $owner = User::factory()->create();
        $owner->role = 'owner';
        $owner->save();
        $member = User::factory()->create();
        $service->setPoolLimit('disk', 1000);
        $service->setUserQuota($member, 1000);
        $key = (string) Str::uuid();
        $service->reserve($member, 'disk', 100, $key);
        $this->actingAs($owner)->withSession(['auth.password_confirmed_at' => time()])
            ->delete('/users/'.$member->id, ['confirm_email' => $member->email])->assertSessionHasErrors('deletion');
        $this->assertModelExists($member);
        $service->release($member, $key);
        $this->delete('/users/'.$member->id, ['confirm_email' => $member->email])->assertRedirect();
        $this->assertModelMissing($member);
        $this->assertNull(StorageReservation::findOrFail($key)->user_id);
    }

    public function test_operator_cannot_reduce_limit_below_accounted_bytes(): void
    {
        $service = $this->service();
        $user = User::factory()->create();
        $service->setPoolLimit('disk', 1000);
        $service->setUserQuota($user, 1000);
        $service->reserve($user, 'disk', 500, (string) Str::uuid());
        $this->artisan('portal:storage-quota', ['scope' => 'user', 'subject' => $user->email, 'bytes' => '400'])->assertExitCode(1);
        $this->assertSame(1000, UserStorageQuota::findOrFail($user->id)->limit_bytes);
    }
}
