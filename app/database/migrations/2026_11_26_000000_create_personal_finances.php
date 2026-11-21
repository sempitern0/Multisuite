<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Personal income and expenses (docs/PERSONAL_FINANCES.md). Amounts in minor units of
        // their own currency: nothing is converted, so changing currency never rewrites history.
        Schema::create('personal_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('entry_key');
            $table->string('kind', 8); // income | expense
            $table->date('occurred_on');
            $table->string('description', 120);
            $table->string('category', 24); // stable key, labels in App\Support\Finance\Categories
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3);
            $table->foreignId('receipt_id')->nullable()->constrained('user_files')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'entry_key']);
            $table->unique(['user_id', 'receipt_id']);
            $table->index(['user_id', 'occurred_on']);
        });
        Schema::create('monthly_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            $table->char('currency', 3);
            $table->unsignedBigInteger('limit_minor');
            $table->timestamps();
            $table->unique(['user_id', 'month', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_budgets');
        Schema::dropIfExists('personal_transactions');
    }
};
