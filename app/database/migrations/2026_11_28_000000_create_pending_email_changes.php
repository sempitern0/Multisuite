<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_email_changes', function (Blueprint $table): void {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('previous_email');
            $table->string('email');
            $table->string('token_hash', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_email_changes');
    }
};
