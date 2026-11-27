<?php

use App\Enums\ExportState;
use App\Enums\ReservationState;
use App\Enums\Role;
use App\Enums\ServiceAccountStatus;
use App\Enums\UploadState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The database refuses roles and states the code does not know (App\Enums). PostgreSQL only:
 * SQLite (tests) cannot add a CHECK to an existing table, and the enums already guard the code.
 */
return new class extends Migration
{
    /** @return array<string, array{0: string, 1: string, 2: list<string>}> */
    private function checks(): array
    {
        return [
            'users_role_check' => ['users', 'role', Role::values()],
            'storage_reservations_state_check' => ['storage_reservations', 'state', ReservationState::values()],
            'private_uploads_state_check' => ['private_uploads', 'state', UploadState::values()],
            'file_exports_state_check' => ['file_exports', 'state', ExportState::values()],
            'service_accounts_status_check' => ['service_accounts', 'status', ServiceAccountStatus::values()],
        ];
    }

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->checks() as $name => [$table, $column, $values]) {
            $list = implode(', ', array_map(fn (string $value) => DB::getPdo()->quote($value), $values));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$column} IN ({$list}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->checks() as $name => [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
