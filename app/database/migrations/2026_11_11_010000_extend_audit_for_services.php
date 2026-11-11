<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_events', function (Blueprint $table) {
            $table->unsignedBigInteger('actor_id')->nullable()->change();
            $table->string('service_id', 64)->nullable()->index();
            $table->unsignedSmallInteger('schema_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_events', function (Blueprint $table) {
            $table->dropColumn(['service_id', 'schema_version']);
        });
        // Keep nullable actors: restoring NOT NULL would destroy system-event history.
    }
};
