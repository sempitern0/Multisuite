<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', function (Blueprint $table) {
            // Set only on a person's fixed top-level folders linked to a service (ConnectedFolders).
            $table->string('connection', 32)->nullable();
            $table->unique(['user_id', 'connection']);
        });
        Schema::table('private_uploads', function (Blueprint $table) {
            // Destination connection decided when the upload is registered: stored unencrypted
            // so the service can read it (decision of the user, 2026-11-15).
            $table->string('connection', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('private_uploads', function (Blueprint $table) {
            $table->dropColumn('connection');
        });
        Schema::table('folders', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'connection']);
            $table->dropColumn('connection');
        });
    }
};
