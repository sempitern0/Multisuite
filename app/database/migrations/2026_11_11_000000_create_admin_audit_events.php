<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_audit_events', function (Blueprint $table) {
            $table->id();
            // Historical identifiers deliberately survive deletion of either user.
            $table->unsignedBigInteger('actor_id')->index();
            $table->string('actor_name');
            $table->unsignedBigInteger('subject_id')->index();
            $table->string('subject_name');
            $table->string('action', 32)->index();
            $table->json('details');
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_events');
    }
};
