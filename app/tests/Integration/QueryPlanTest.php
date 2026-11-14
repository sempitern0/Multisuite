<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Guards the indexes that PostgreSQL needs for the portal's lookups and foreign key
 * checks. With sequential scans disabled, each query must pick the expected index;
 * a missing or unusable index makes the planner fall back to another plan.
 */
class QueryPlanTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function queries(): array
    {
        return [
            'children of a folder (folder delete check)' => ['select id from folders where parent_id = 1', 'folders_parent_id_index'],
            'files of a folder (folder delete check)' => ['select id from user_files where folder_id = 1', 'user_files_folder_id_index'],
            'files remembering a folder (trash restore)' => ['select id from user_files where original_folder_id = 1', 'user_files_original_folder_id_index'],
            'versions of a file, newest first (retention)' => ['select id from user_files where version_of_id = 1 order by superseded_at desc', 'user_files_version_of_id_superseded_at_index'],
            'invitations of an issuer (user delete)' => ['select id from invitations where invited_by = 1', 'invitations_invited_by_index'],
            'accounts created by an admin (user delete)' => ['select id from users where created_by_admin_id = 1', 'users_created_by_admin_id_index'],
            'reservations of a pool (pool delete)' => ["select id from storage_reservations where pool_id = 'disk'", 'storage_reservations_pool_id_index'],
            'expired reservations (cleanup)' => ["select id from storage_reservations where state = 'reserved' and expires_at < now()", 'storage_reservations_state_expires_at_index'],
            'audit filtered by action' => ["select id from admin_audit_events where action = 'user.deleted'", 'admin_audit_events_action_index'],
        ];
    }

    public function test_postgres_uses_the_expected_indexes(): void
    {
        if (getenv('QUERY_PLAN_TEST') !== '1') {
            $this->markTestSkipped('Disposable PostgreSQL required.');
        }
        $this->assertSame('pgsql', config('database.default'));
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);

        DB::transaction(function (): void {
            DB::statement('SET LOCAL enable_seqscan = off');
            foreach (self::queries() as $label => [$sql, $index]) {
                $plan = implode("\n", array_map(fn (object $row): string => (string) current((array) $row), DB::select('EXPLAIN '.$sql)));
                $this->assertStringContainsString($index, $plan, "{$label}: expected {$index}.\n{$plan}");
            }
        });
    }
}
