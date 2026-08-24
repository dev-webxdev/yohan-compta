<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('overtime_payments', 'period_reference')) {
            Schema::table('overtime_payments', function (Blueprint $table): void {
                $table->dropColumn('period_reference');
            });
        }

        if (Schema::hasColumn('monthly_salaries', 'note')) {
            Schema::table('monthly_salaries', function (Blueprint $table): void {
                $table->dropColumn('note');
            });
        }

        if (Schema::hasIndex('monthly_salaries', 'monthly_salaries_month_index')) {
            Schema::table('monthly_salaries', function (Blueprint $table): void {
                $table->dropIndex('monthly_salaries_month_index');
            });
        }
    }

    public function down(): void
    {
        Schema::table('overtime_payments', function (Blueprint $table): void {
            $table->string('period_reference', 255)->nullable();
        });

        Schema::table('monthly_salaries', function (Blueprint $table): void {
            $table->text('note')->nullable();
            $table->index('month');
        });
    }
};
