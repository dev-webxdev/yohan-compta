<?php

namespace App\Services;

use App\Models\SettingPeriod;

final class SettingsService
{
    public function forDate(string $date): SettingPeriod
    {
        return SettingPeriod::query()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->firstOrFail();
    }

    public function all(): \Illuminate\Database\Eloquent\Collection
    {
        return SettingPeriod::query()->orderByDesc('effective_from')->get();
    }
}
