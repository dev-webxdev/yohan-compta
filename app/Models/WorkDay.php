<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WorkDay extends Model
{
    protected $fillable = [
        'date',
        'driving_minutes',
        'warehouse_minutes',
        'end_time_minutes',
        'meal_allowance_mode',
        'meal_allowance_forced_cents',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'driving_minutes' => 'integer',
            'warehouse_minutes' => 'integer',
            'end_time_minutes' => 'integer',
            'meal_allowance_forced_cents' => 'integer',
        ];
    }
}
