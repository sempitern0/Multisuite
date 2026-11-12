<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreign('id')->references('id')->on('storage_reservations')->restrictOnDelete();
            $table->string('location_id', 64);
            $table->string('root_hash', 64);
            $table->string('state', 16)->default('pending')->index();
            $table->string('mime')->nullable();
            $table->string('sha256', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('private_uploads');
    }
};
