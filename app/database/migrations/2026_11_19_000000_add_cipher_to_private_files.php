<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Encryption at rest (FileCipher): null = stored as plaintext before encryption existed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_uploads', function (Blueprint $table) {
            $table->string('cipher', 16)->nullable()->after('sha256');
        });
        Schema::table('file_exports', function (Blueprint $table) {
            $table->string('cipher', 16)->nullable()->after('bytes');
        });
    }

    public function down(): void
    {
        Schema::table('private_uploads', fn (Blueprint $table) => $table->dropColumn('cipher'));
        Schema::table('file_exports', fn (Blueprint $table) => $table->dropColumn('cipher'));
    }
};
