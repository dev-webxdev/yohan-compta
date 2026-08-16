<?php

namespace App\Services;

use App\Models\SettingPeriod;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

final class SettingsService
{
    /** @var Collection<int,SettingPeriod>|null */
    private ?Collection $periods = null;

    public function forDate(string $date): SettingPeriod
    {
        $period = $this->periods()
            ->last(static fn (SettingPeriod $period): bool => $period->effective_from->format('Y-m-d') <= $date);

        if (!$period) {
            throw (new ModelNotFoundException())->setModel(SettingPeriod::class);
        }

        return $period;
    }

    /** @return Collection<int,SettingPeriod> */
    private function periods(): Collection
    {
        return $this->periods ??= SettingPeriod::query()->orderBy('effective_from')->get();
    }
}
