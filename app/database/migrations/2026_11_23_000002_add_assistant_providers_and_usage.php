<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** MCP-02: providers configured by the owner, and the daily token limit (docs/MCP.md). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->unsignedInteger('daily_tokens')->default(200000);
        });

        // External AI providers added by the owner (decision D2). The key is encrypted with
        // APP_KEY (cast `encrypted`) and never shown again; key_hint keeps its last 4 characters.
        Schema::create('assistant_providers', function (Blueprint $table) {
            $table->id();
            $table->string('driver', 32);
            $table->string('model', 100);
            $table->text('api_key');
            $table->string('key_hint', 8);
            $table->unsignedSmallInteger('position')->default(1);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
            $table->index(['enabled', 'position']);
        });

        // Tokens spent per person and day, for the daily limit.
        Schema::create('assistant_usage', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('prompts')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->primary(['user_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_usage');
        Schema::dropIfExists('assistant_providers');
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->dropColumn('daily_tokens');
        });
    }
};
