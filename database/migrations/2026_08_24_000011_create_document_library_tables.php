<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();
            $table->index(['parent_id', 'name']);
        });

        Schema::create('library_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_name', 80)->unique();
            $table->string('mime_type', 160)->nullable();
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
            $table->index(['folder_id', 'original_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('library_documents');
        Schema::dropIfExists('document_folders');
    }
};
