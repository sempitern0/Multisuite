<?php

use App\Enums\ServiceAccountStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Service accounts gain the «lock_pending» status (a suspension's remote block waiting for its
 * retry). Recreates the PostgreSQL CHECK from 2026_11_27_000000 with the current statuses, so
 * databases migrated before this change accept it. Fixed list: a migration must not change later.
 */
return new class extends Migration
{
    private const STATUSES = ['pending', 'ready', 'awaiting_password', 'lock_pending', 'locked', 'expired', 'conflict', 'configuration'];

    public function up(): void
    {
        $this->replace(self::STATUSES);
    }

    public function down(): void
    {
        DB::table('service_accounts')->where('status', ServiceAccountStatus::LockPending->value)->update(['status' => ServiceAccountStatus::Locked->value]);
        $this->replace(array_values(array_diff(self::STATUSES, ['lock_pending'])));
    }

    /** @param list<string> $statuses */
    private function replace(array $statuses): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        $list = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $statuses));
        DB::statement('ALTER TABLE service_accounts DROP CONSTRAINT IF EXISTS service_accounts_status_check');
        DB::statement("ALTER TABLE service_accounts ADD CONSTRAINT service_accounts_status_check CHECK (status IN ({$list}))");
    }
};
