<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MonthlySalary extends Model
{
    protected $fillable = ['month', 'net_amount_cents', 'note'];

    protected function casts(): array
    {
        return [
            'net_amount_cents' => 'integer',
        ];
    }
}
