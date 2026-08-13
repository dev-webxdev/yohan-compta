<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('work_days', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedSmallInteger('driving_minutes')->default(0);
            $table->unsignedSmallInteger('warehouse_minutes')->default(0);
            $table->unsignedSmallInteger('end_time_minutes')->nullable();
            $table->string('meal_allowance_mode', 12)->default('auto');
            $table->unsignedInteger('meal_allowance_forced_cents')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_days');
    }
};
