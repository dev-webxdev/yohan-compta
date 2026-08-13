<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('setting_periods', function (Blueprint $table): void {
            $table->id();
            $table->date('effective_from')->unique();
            $table->unsignedInteger('hourly_rate_cents');
            $table->unsignedInteger('weekly_threshold_minutes');
            $table->unsignedInteger('meal_allowance_cents');
            $table->unsignedInteger('meal_allowance_time_minutes');
        });

        DB::table('setting_periods')->insert([
            'effective_from' => '2000-01-01',
            'hourly_rate_cents' => 1231,
            'weekly_threshold_minutes' => 2100,
            'meal_allowance_cents' => 1600,
            'meal_allowance_time_minutes' => 855,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_periods');
    }
};
