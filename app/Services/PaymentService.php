<?php

namespace App\Services;

use App\Models\OvertimePayment;

final class PaymentService
{
    public function create(string $date, int $amountCents, ?int $hoursPaidMinutes, ?string $note, ?string $periodReference): OvertimePayment
    {
        return OvertimePayment::query()->create([
            'payment_date' => $date,
            'amount_cents' => $amountCents,
            'hours_paid_minutes' => $hoursPaidMinutes,
            'note' => $note,
            'period_reference' => $periodReference,
        ]);
    }
}
