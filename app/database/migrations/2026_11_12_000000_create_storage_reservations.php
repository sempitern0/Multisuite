<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_pools', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->unsignedBigInteger('limit_bytes');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
        });
        Schema::create('user_storage_quotas', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('limit_bytes');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->timestamps();
        });
        Schema::create('storage_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('pool_id', 64);
            $table->foreign('pool_id')->references('id')->on('storage_pools')->restrictOnDelete();
            $table->unsignedBigInteger('bytes');
            $table->unsignedBigInteger('actual_bytes')->nullable();
            $table->string('state', 16)->default('reserved');
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['user_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_reservations');
        Schema::dropIfExists('user_storage_quotas');
        Schema::dropIfExists('storage_pools');
    }
};
