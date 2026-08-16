<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OvertimePayment extends Model
{
    protected $fillable = ['payment_date', 'amount_cents', 'hours_paid_minutes', 'period_reference'];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date:Y-m-d',
            'amount_cents' => 'integer',
            'hours_paid_minutes' => 'integer',
        ];
    }
}
