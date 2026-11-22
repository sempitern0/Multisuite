<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Content published for everyone (S04b). The stored file is the contributor's upload; the
        // row survives the contributor (published content becomes common property).
        Schema::create('publications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('upload_id')->unique();
            $table->foreign('upload_id')->references('id')->on('private_uploads')->restrictOnDelete();
            $table->string('connection', 16); // ConnectedFolders::CONNECTIONS key
            $table->string('title', 200);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('contributor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_file_id')->nullable()->constrained('user_files')->nullOnDelete();
            $table->string('state', 12)->index();
            $table->timestamp('requested_at');
            $table->timestamp('withdrawal_requested_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['connection', 'state']);
            $table->index('contributor_id');
        });
        // Bytes of published content: no longer in anyone's quota, still on the disk (pool).
        Schema::create('common_storage', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
        });
        DB::table('common_storage')->insert(['id' => 1, 'used_bytes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE publications ADD CONSTRAINT publications_state_check CHECK (state IN ('requested', 'published'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('common_storage');
        Schema::dropIfExists('publications');
    }
};
