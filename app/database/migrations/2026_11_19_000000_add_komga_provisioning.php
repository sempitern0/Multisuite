<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_connections', function (Blueprint $table) {
            $table->string('service')->primary();
            $table->text('secret');
            $table->string('operator_id');
            $table->timestamps();
        });
        Schema::table('service_accounts', function (Blueprint $table) {
            $table->string('external_id')->nullable();
            $table->string('remote_email')->nullable();
            $table->uuid('media_id')->nullable();
            $table->json('library_ids')->nullable();
            $table->unique(['service', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_accounts', function (Blueprint $table) {
            $table->dropUnique(['service', 'external_id']);
            $table->dropColumn(['external_id', 'remote_email', 'media_id', 'library_ids']);
        });
        Schema::dropIfExists('service_connections');
    }
};
