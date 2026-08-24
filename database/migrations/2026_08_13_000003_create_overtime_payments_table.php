<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('overtime_payments', function (Blueprint $table): void {
            $table->id();
            $table->date('payment_date');
            $table->unsignedInteger('amount_cents');
            $table->unsignedInteger('hours_paid_minutes')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('payment_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_payments');
    }
};
