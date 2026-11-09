<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('member');
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('role')->default('member');
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
        });
        // Legacy codes lack an authorized issuer and must be reissued.
        DB::table('invitations')->whereNull('used_at')->update(['expires_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn('role');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
