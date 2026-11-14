<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL (development and production) does not index foreign key columns on its
 * own: deleting a folder, a file version head, a pool or a user then scans the
 * referencing table, and lookups by these columns cannot use an index.
 * Composite indexes whose first column is user_id do not cover them.
 */
return new class extends Migration
{
    /** @var array<string, list<list<string>>> */
    private const INDEXES = [
        'folders' => [['parent_id']],
        'user_files' => [['folder_id'], ['original_folder_id'], ['version_of_id', 'superseded_at']],
        'invitations' => [['invited_by']],
        'users' => [['created_by_admin_id']],
        'storage_reservations' => [['pool_id']],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->index($columns);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes): void {
                foreach ($indexes as $columns) {
                    $blueprint->dropIndex($columns);
                }
            });
        }
    }
};
