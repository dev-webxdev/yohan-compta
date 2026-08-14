<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->unsignedSmallInteger('default_start_time_minutes')->default(465)->after('effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->dropColumn('default_start_time_minutes');
        });
    }
};
