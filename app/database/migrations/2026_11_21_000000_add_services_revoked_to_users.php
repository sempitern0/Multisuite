<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Services an administrator left out or turned off on purpose: never granted
            // automatically when the person signs in (ServiceAccounts::grantNewServices).
            $table->json('services_revoked')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('services_revoked');
        });
    }
};
