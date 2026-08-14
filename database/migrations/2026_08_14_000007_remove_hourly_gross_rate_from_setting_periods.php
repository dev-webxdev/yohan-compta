<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->dropColumn('hourly_gross_rate_cents');
        });
    }

    public function down(): void
    {
        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->unsignedInteger('hourly_gross_rate_cents')
                ->default(1231)
                ->after('default_start_time_minutes');
        });
    }
};
