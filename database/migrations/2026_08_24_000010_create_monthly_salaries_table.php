<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('monthly_salaries', function (Blueprint $table): void {
            $table->id();
            $table->string('month', 7)->unique();
            $table->unsignedInteger('net_amount_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_salaries');
    }
};
