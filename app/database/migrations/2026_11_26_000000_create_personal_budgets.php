<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->unsignedBigInteger('limit_cents');
            $table->unique(['user_id', 'month']);
        });
        Schema::create('personal_expenses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('entry_key');
            $table->date('spent_on');
            $table->string('description', 120);
            $table->string('category', 32);
            $table->unsignedBigInteger('amount_cents');
            $table->foreignId('receipt_id')->nullable()->constrained('user_files')->nullOnDelete();
            $table->unique(['user_id', 'entry_key']);
            $table->unique(['user_id', 'receipt_id']);
            $table->index(['user_id', 'spent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_expenses');
        Schema::dropIfExists('monthly_budgets');
    }
};
