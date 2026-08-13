<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SettingPeriod extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'effective_from',
        'hourly_rate_cents',
        'weekly_threshold_minutes',
        'meal_allowance_cents',
        'meal_allowance_time_minutes',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'hourly_rate_cents' => 'integer',
            'weekly_threshold_minutes' => 'integer',
            'meal_allowance_cents' => 'integer',
            'meal_allowance_time_minutes' => 'integer',
        ];
    }
}
