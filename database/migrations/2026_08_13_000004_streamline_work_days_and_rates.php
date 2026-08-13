<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->unsignedSmallInteger('start_time_minutes')->default(465)->after('date');
            $table->dropColumn(['end_time_minutes', 'note']);
        });

        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->renameColumn('hourly_rate_cents', 'hourly_gross_rate_cents');
            $table->unsignedInteger('hourly_net_rate_cents')->default(974)->after('hourly_gross_rate_cents');
        });
    }

    public function down(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->unsignedSmallInteger('end_time_minutes')->nullable();
            $table->text('note')->nullable();
            $table->dropColumn('start_time_minutes');
        });

        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->dropColumn('hourly_net_rate_cents');
            $table->renameColumn('hourly_gross_rate_cents', 'hourly_rate_cents');
        });
    }
};
