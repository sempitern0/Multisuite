<?php

namespace Tests\Feature;

use App\Models\AdminAuditEvent;
use App\Models\PrivateUpload;
use App\Models\StoragePool;
use App\Models\StorageReservation;
use App\Models\User;
use App\Models\UserStorageQuota;
use App\Services\Storage\StorageInventory;
use App\Services\Storage\StorageReservations;
use App\Support\Bytes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class StorageAdminTest extends TestCase
{
    use RefreshDatabase;

    private const GB = 1_073_741_824;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(StorageInventory::class, function (MockInterface $mock): void {
            $mock->shouldReceive('inspect')->andReturn([
                'schema_version' => 1,
                'read_only' => true,
                'write_admission_enabled' => false,
                'locations' => [['id' => 'music', 'group' => 'disk', 'status' => 'observed']],
                'capacity_groups' => [[
                    'id' => 'disk', 'status' => 'observed', 'total_bytes' => 1000 * self::GB,
                    'free_bytes' => 600 * self::GB, 'reserve_bytes' => 50 * self::GB, 'headroom_bytes' => 550 * self::GB,
                ]],
            ]);
        });
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create();
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function confirmed(User $user): static
    {
        return $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
    }

    public function test_only_owner_and_admin_can_open_storage_with_password_confirmation(): void
    {
        $this->get('/storage')->assertRedirect('/login');
        $this->actingAs($this->actor('member'))->get('/storage')->assertForbidden();
        $this->actingAs($this->actor('admin'))->get('/storage')->assertRedirect(route('password.confirm'));
        $this->confirmed($this->actor('owner'))->get('/storage')->assertOk()
            ->assertSee('Almacenamiento')->assertSee('disk')->assertSee('Máximo recomendado');
    }

    public function test_owner_sets_disk_limit_with_human_units_and_it_is_audited(): void
    {
        $owner = $this->actor('owner');
        $this->confirmed($owner)->from('/storage')
            ->put('/storage/pools/disk', ['preset' => 'custom', 'amount' => '1,5', 'unit' => 'GB'])
            ->assertRedirect('/storage')->assertSessionHasNoErrors();
        $this->assertSame((int) (1.5 * self::GB), StoragePool::findOrFail('disk')->limit_bytes);
        $event = AdminAuditEvent::where('action', 'storage.pool_limit')->firstOrFail();
        $this->assertSame(['pool' => 'disk', 'limit_bytes' => (int) (1.5 * self::GB), 'previous_limit_bytes' => null], $event->details);
        $this->get('/admin/activity')->assertOk()->assertSee('Límite de disco modificado');
    }

    public function test_unknown_or_unavailable_disk_is_rejected_next_to_its_form(): void
    {
        $this->confirmed($this->actor('owner'))->from('/storage')
            ->put('/storage/pools/other', ['preset' => (string) self::GB])
            ->assertSessionHasErrors('pool_other');
        $this->assertNull(StoragePool::find('other'));
    }

    public function test_admin_manages_members_only_and_cannot_change_disks_or_default(): void
    {
        $admin = $this->actor('admin');
        $member = $this->actor('member');
        $this->confirmed($admin);
        $this->put('/storage/pools/disk', ['preset' => (string) self::GB])->assertForbidden();
        $this->put('/storage/default', ['preset' => (string) self::GB])->assertForbidden();
        foreach ([$this->actor('owner'), $this->actor('admin'), $admin] as $target) {
            $this->put('/storage/users/'.$target->id, ['preset' => (string) self::GB])->assertForbidden();
        }
        $this->from('/storage')->put('/storage/users/'.$member->id, ['preset' => (string) (5 * self::GB)])->assertRedirect('/storage');
        $this->assertSame(5 * self::GB, UserStorageQuota::findOrFail($member->id)->limit_bytes);
        $this->assertDatabaseHas('admin_audit_events', ['action' => 'storage.user_quota', 'actor_id' => $admin->id, 'subject_id' => $member->id]);
    }

    public function test_owner_can_set_own_quota_and_page_shows_who_is_manageable(): void
    {
        $owner = $this->actor('owner');
        $this->confirmed($owner)->from('/storage')->put('/storage/users/'.$owner->id, ['preset' => (string) (10 * self::GB)])
            ->assertSessionHasNoErrors();
        $this->assertSame(10 * self::GB, UserStorageQuota::findOrFail($owner->id)->limit_bytes);
        $this->get('/storage')->assertSee('Guardar espacio de '.$owner->name);
    }

    public function test_limit_below_current_usage_is_rejected_and_unchanged(): void
    {
        $owner = $this->actor('owner');
        $member = $this->actor('member');
        $service = app(StorageReservations::class);
        $service->setPoolLimit('disk', 100 * self::GB);
        $service->setUserQuota($member, 10 * self::GB);
        $key = (string) Str::uuid();
        $service->reserve($member, 'disk', 4 * self::GB, $key);
        $service->commit($member, $key, 4 * self::GB);

        $this->confirmed($owner)->from('/storage')->put('/storage/users/'.$member->id, ['preset' => (string) self::GB])
            ->assertSessionHasErrors('quota_'.$member->id);
        $this->assertSame(10 * self::GB, UserStorageQuota::findOrFail($member->id)->limit_bytes);
        $this->assertDatabaseMissing('admin_audit_events', ['action' => 'storage.user_quota']);
    }

    public function test_invalid_amounts_are_rejected(): void
    {
        $owner = $this->actor('owner');
        $this->confirmed($owner)->from('/storage');
        foreach (['1e5', '-3', '', '1234567', '1.234'] as $amount) {
            $this->put('/storage/default', ['preset' => 'custom', 'amount' => $amount, 'unit' => 'GB'])->assertSessionHasErrors();
        }
        $this->put('/storage/default', ['preset' => 'custom', 'amount' => '5', 'unit' => 'PB'])->assertSessionHasErrors('unit');
        $this->put('/storage/default', ['preset' => '12abc'])->assertSessionHasErrors('preset');
        $this->assertSame(0, app(StorageReservations::class)->defaultUserQuota());
    }

    public function test_default_quota_applies_lazily_only_to_users_without_explicit_quota(): void
    {
        $owner = $this->actor('owner');
        $newcomer = $this->actor('member');
        $explicit = $this->actor('member');
        $service = app(StorageReservations::class);
        $service->setPoolLimit('disk', 100 * self::GB);
        $service->setUserQuota($explicit, self::GB);

        $this->confirmed($owner)->from('/storage')->put('/storage/default', ['preset' => (string) (2 * self::GB)])->assertSessionHasNoErrors();
        $this->assertSame(self::GB, UserStorageQuota::findOrFail($explicit->id)->limit_bytes);
        $this->assertNull(UserStorageQuota::find($newcomer->id));

        $service->reserve($newcomer, 'disk', self::GB, (string) Str::uuid());
        $this->assertSame(2 * self::GB, UserStorageQuota::findOrFail($newcomer->id)->limit_bytes);
        $this->expectException(RuntimeException::class);
        $service->reserve($newcomer, 'disk', (int) (1.5 * self::GB), (string) Str::uuid());
    }

    public function test_without_default_users_without_quota_still_cannot_reserve(): void
    {
        $member = $this->actor('member');
        $service = app(StorageReservations::class);
        $service->setPoolLimit('disk', 100 * self::GB);
        $this->expectException(RuntimeException::class);
        $service->reserve($member, 'disk', 1, (string) Str::uuid());
    }

    public function test_member_sees_own_space_on_dashboard_only_when_assigned(): void
    {
        $member = $this->actor('member');
        $this->actingAs($member)->get('/dashboard')->assertDontSee('Tu almacenamiento');
        app(StorageReservations::class)->setUserQuota($member, 3 * self::GB);
        $this->get('/dashboard')->assertSee('Tu almacenamiento')->assertSee('de '.Bytes::format(3 * self::GB));
    }

    public function test_expired_reservations_are_shown_split_by_automatic_and_manual_cleanup(): void
    {
        $member = $this->actor('member');
        $service = app(StorageReservations::class);
        $service->setPoolLimit('disk', 100 * self::GB);
        $service->setUserQuota($member, 10 * self::GB);
        $withUpload = (string) Str::uuid();
        $orphan = (string) Str::uuid();
        $service->reserve($member, 'disk', self::GB, $withUpload);
        $service->reserve($member, 'disk', 2 * self::GB, $orphan);
        $upload = new PrivateUpload;
        $upload->id = $withUpload;
        $upload->location_id = 'music';
        $upload->root_hash = str_repeat('a', 64);
        $upload->state = 'pending';
        $upload->save();
        StorageReservation::query()->update(['expires_at' => now()->subHour()]);

        $this->confirmed($this->actor('owner'))->get('/storage')->assertOk()
            ->assertSee('2 subidas interrumpidas siguen ocupando '.Bytes::format(3 * self::GB))
            ->assertSee(Bytes::format(self::GB).' se liberarán solos')
            ->assertSee(Bytes::format(2 * self::GB).' no tienen limpieza automática');
        // Display only: nothing is released by viewing the page.
        $this->assertSame(3 * self::GB, StoragePool::findOrFail('disk')->reserved_bytes);
    }

    public function test_quota_command_accepts_human_units_and_rejects_ambiguous_values(): void
    {
        $member = $this->actor('member');
        $this->artisan('portal:storage-quota', ['scope' => 'user', 'subject' => $member->email, 'bytes' => '1,5GB'])->assertExitCode(0);
        $this->assertSame((int) (1.5 * self::GB), UserStorageQuota::findOrFail($member->id)->limit_bytes);
        $this->artisan('portal:storage-quota', ['scope' => 'user', 'subject' => $member->email, 'bytes' => '2 tb'])->assertExitCode(0);
        $this->assertSame(2 * 1_099_511_627_776, UserStorageQuota::findOrFail($member->id)->limit_bytes);
        foreach (['1e9', '5PB', '1.234GB', '-1GB'] as $value) {
            $this->artisan('portal:storage-quota', ['scope' => 'user', 'subject' => $member->email, 'bytes' => $value])->assertExitCode(1);
        }
        $this->assertSame(2 * 1_099_511_627_776, UserStorageQuota::findOrFail($member->id)->limit_bytes);
    }
}
