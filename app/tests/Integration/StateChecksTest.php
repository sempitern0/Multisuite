<?php

namespace Tests\Integration;

use App\Enums\Role;
use App\Enums\ServiceAccountStatus;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** PostgreSQL refuses roles and states outside App\Enums (migrations 2026_11_27_000000 and 2026_11_28_000002). */
class StateChecksTest extends TestCase
{
    public function test_postgres_rejects_unknown_roles_and_states(): void
    {
        if (getenv('QUERY_PLAN_TEST') !== '1') {
            $this->markTestSkipped('Disposable PostgreSQL required.');
        }
        $this->assertSame('pgsql', config('database.default'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        $user = User::factory()->create(['role' => Role::Admin->value]);
        $this->assertSame('admin', $user->fresh()?->role);

        foreach (['users' => ['role', 'superuser'], 'storage_reservations' => ['state', 'lost'], 'private_uploads' => ['state', 'lost'],
            'file_exports' => ['state', 'lost'], 'service_accounts' => ['status', 'lost']] as $table => [$column, $value]) {
            $constraint = DB::scalar('select count(*) from pg_constraint where conname = ?', [$table.'_'.$column.'_check']);
            $this->assertSame(1, (int) $constraint, $table);
        }
        // Every status the code uses is accepted, including a suspension's pending remote block.
        foreach (ServiceAccountStatus::cases() as $status) {
            DB::table('service_accounts')->insert(['user_id' => $user->id, 'service' => 'probe-'.$status->value, 'remote_id' => (string) Str::uuid(),
                'username' => 'probe', 'status' => $status->value, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame(count(ServiceAccountStatus::cases()), DB::table('service_accounts')->where('user_id', $user->id)->count());

        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['role' => 'superuser']);
    }
}
