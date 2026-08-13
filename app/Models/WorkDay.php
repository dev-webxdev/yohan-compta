<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkDay extends Model
{
    protected $fillable = [
        'date',
        'start_time_minutes',
        'driving_minutes',
        'warehouse_minutes',
        'meal_allowance_mode',
        'meal_allowance_forced_cents',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'start_time_minutes' => 'integer',
            'driving_minutes' => 'integer',
            'warehouse_minutes' => 'integer',
            'meal_allowance_forced_cents' => 'integer',
        ];
    }
}
