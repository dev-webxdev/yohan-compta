<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->unsignedSmallInteger('planned_minutes')->nullable()->after('warehouse_minutes');
            $table->boolean('is_leave')->default(false)->after('is_rest');
        });

        Schema::create('document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('document_id')->constrained('library_documents')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->string('target_key', 32);
            $table->timestamps();
            $table->unique(['document_id', 'target_type', 'target_key']);
            $table->index(['target_type', 'target_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_links');

        Schema::table('work_days', function (Blueprint $table): void {
            $table->dropColumn(['planned_minutes', 'is_leave']);
        });
    }
};
