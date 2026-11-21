<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-05 (external MCP clients) and MCP-07 (search by meaning), both off by default.
 * Indexed text is stored encrypted (APP_KEY) and only for people who accepted it (D3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->boolean('semantic_enabled')->default(false);
            $table->boolean('external_enabled')->default(false);
        });

        Schema::create('assistant_indexed_files', function (Blueprint $table) {
            $table->foreignId('user_file_id')->primary()->constrained('user_files')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('upload_id', 64);
            $table->string('model', 160);
            $table->unsignedSmallInteger('chunks')->default(0);
            $table->timestamp('indexed_at');
            $table->index(['user_id', 'model']);
        });

        Schema::create('assistant_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_file_id')->constrained('user_files')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->text('content');
            // float32 vector, base64 (portable between PostgreSQL and SQLite).
            $table->text('embedding');
            $table->string('model', 160);
            $table->index(['user_id', 'model']);
            $table->index('user_file_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_chunks');
        Schema::dropIfExists('assistant_indexed_files');
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->dropColumn(['semantic_enabled', 'external_enabled']);
        });
    }
};
