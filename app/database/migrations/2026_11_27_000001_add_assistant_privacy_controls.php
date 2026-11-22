<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('assistant_content_revision')->default(0);
        });
        Schema::table('folders', function (Blueprint $table): void {
            $table->boolean('assistant_excluded')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('folders', function (Blueprint $table): void {
            $table->dropColumn('assistant_excluded');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('assistant_content_revision');
        });
    }
};
