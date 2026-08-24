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
        'planned_minutes',
        'is_rest',
        'is_leave',
        'meal_allowance_mode',
        'meal_allowance_forced_cents',
        'client_write_version',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'start_time_minutes' => 'integer',
            'driving_minutes' => 'integer',
            'warehouse_minutes' => 'integer',
            'planned_minutes' => 'integer',
            'is_rest' => 'boolean',
            'is_leave' => 'boolean',
            'meal_allowance_forced_cents' => 'integer',
            'client_write_version' => 'integer',
        ];
    }
}
