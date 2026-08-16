<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->unsignedBigInteger('client_write_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->dropColumn('client_write_version');
        });
    }
};
