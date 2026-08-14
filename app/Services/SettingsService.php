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
}
