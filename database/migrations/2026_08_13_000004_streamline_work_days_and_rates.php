<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('work_days', function (Blueprint $table): void {
            $table->unsignedSmallInteger('start_time_minutes')->default(465)->after('date');
        });

        DB::table('work_days')
            ->whereNotNull('end_time_minutes')
            ->orderBy('id')
            ->each(function (object $day): void {
                $worked = (int) $day->driving_minutes + (int) $day->warehouse_minutes;
                $start = (int) $day->end_time_minutes - $worked;

                if ($start >= 0 && $start < 1440) {
                    DB::table('work_days')->where('id', $day->id)->update(['start_time_minutes' => $start]);
                }
            });

        Schema::table('work_days', function (Blueprint $table): void {
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
        });

        DB::table('work_days')
            ->orderBy('id')
            ->each(function (object $day): void {
                $end = ((int) $day->start_time_minutes + (int) $day->driving_minutes + (int) $day->warehouse_minutes) % 1440;
                DB::table('work_days')->where('id', $day->id)->update(['end_time_minutes' => $end]);
            });

        Schema::table('work_days', function (Blueprint $table): void {
            $table->dropColumn('start_time_minutes');
        });

        Schema::table('setting_periods', function (Blueprint $table): void {
            $table->dropColumn('hourly_net_rate_cents');
            $table->renameColumn('hourly_gross_rate_cents', 'hourly_rate_cents');
        });
    }
};
