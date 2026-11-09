<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('service_access')->nullable();
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->json('services')->nullable();
        });
        Schema::create('service_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('service');
            $table->uuid('remote_id')->unique();
            $table->string('username');
            $table->string('status')->default('pending');
            $table->text('pending_password')->nullable();
            $table->timestamp('credential_expires_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'service']);
            $table->unique(['service', 'username']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_accounts');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('service_access');
        });
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('services');
        });
    }
};
