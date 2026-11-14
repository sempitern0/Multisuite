<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_reservations', function (Blueprint $table) {
            // false: temporary copy (ZIP export) checked against disk space, not the person's quota.
            $table->boolean('charges_user')->default(true);
        });
        Schema::create('file_exports', function (Blueprint $table) {
            // Same UUID as its temporary reservation.
            $table->uuid('id')->primary();
            $table->foreign('id')->references('id')->on('storage_reservations')->restrictOnDelete();
            // Deleting a user with active exports is blocked by prepareUserDeletion.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state', 16)->index();
            $table->string('name', 200);
            $table->string('location_id', 64);
            $table->string('root_hash', 64);
            // Snapshot taken at request time: list of {upload, path}.
            $table->json('items');
            $table->unsignedInteger('item_count');
            $table->unsignedBigInteger('estimated_bytes');
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_exports');
        Schema::table('storage_reservations', function (Blueprint $table) {
            $table->dropColumn('charges_user');
        });
    }
};
