<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // A folder with children or files cannot be removed implicitly.
            $table->foreignId('parent_id')->nullable()->constrained('folders')->restrictOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->index(['user_id', 'parent_id']);
        });
        Schema::create('user_files', function (Blueprint $table) {
            $table->id();
            // Accounting already blocks deleting a user with stored bytes; leftover
            // cancelled entries must not block the deletion of an empty account.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('folders')->restrictOnDelete();
            $table->uuid('upload_id')->unique();
            $table->foreign('upload_id')->references('id')->on('private_uploads')->restrictOnDelete();
            $table->string('name', 200);
            $table->timestamps();
            $table->index(['user_id', 'folder_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_files');
        Schema::dropIfExists('folders');
    }
};
