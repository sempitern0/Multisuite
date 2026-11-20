<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row (id 1): only the owner changes it (docs/MCP.md). Provider keys never live here.
        Schema::create('assistant_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->boolean('enabled')->default(false);
            $table->json('tools')->nullable();
            $table->unsignedInteger('daily_calls')->default(200);
            $table->timestamps();
        });

        // People the owner allows to use the assistant; the owner always can when it is on.
        Schema::create('assistant_members', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignId('granted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Allowlisted audit and daily budget: never arguments, contents, file names or model output.
        Schema::create('assistant_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 64);
            $table->string('outcome', 16);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_tool_calls');
        Schema::dropIfExists('assistant_members');
        Schema::dropIfExists('assistant_settings');
    }
};
