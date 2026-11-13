<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_files', function (Blueprint $table) {
            // Trash: the entry leaves its folder (so the folder can be emptied) and
            // remembers it for restore; bytes stay counted until a verified purge.
            $table->timestamp('trashed_at')->nullable()->index();
            $table->foreignId('original_folder_id')->nullable()->constrained('folders')->nullOnDelete();
            // Versions: older uploads with the same name point to the current entry.
            // Restrict: a current entry is purged only after its versions.
            $table->foreignId('version_of_id')->nullable()->constrained('user_files')->restrictOnDelete();
            $table->timestamp('superseded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_of_id');
            $table->dropConstrainedForeignId('original_folder_id');
            $table->dropColumn(['trashed_at', 'superseded_at']);
        });
    }
};
