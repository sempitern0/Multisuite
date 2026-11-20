<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MCP-03 (decision D3): file contents reach the AI provider only if the owner allows it
 * and the person has accepted it for their own files.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->boolean('content_allowed')->default(false);
        });

        Schema::create('assistant_consents', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->timestamp('content_accepted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_consents');
        Schema::table('assistant_settings', function (Blueprint $table) {
            $table->dropColumn('content_allowed');
        });
    }
};
