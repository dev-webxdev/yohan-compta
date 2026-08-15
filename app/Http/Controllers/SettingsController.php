<?php

namespace App\Http\Controllers;

use App\Models\SettingPeriod;
use App\Services\DatabaseMaintenanceService;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SettingsController
{
    public function index(SettingsService $settings, DatabaseMaintenanceService $database): View
    {
        $backups = $database->backups();

        return view('settings', [
            'current' => $settings->forDate(now()->format('Y-m-d')),
            'backups' => $backups,
            'backupSummary' => [
                'count' => count($backups),
                'size_bytes' => array_sum(array_column($backups, 'size_bytes')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.DateRange::MIN_DATE,
                'before_or_equal:'.DateRange::MAX_DATE,
            ],
            'default_start_time' => ['required', 'regex:/^([01]?\d|2[0-3])(?::[0-5]\d)?$/'],
            'hourly_net_rate' => ['required', 'string'],
            'weekly_threshold' => ['required', 'regex:/^\d{1,3}:[0-5]\d$/'],
            'meal_allowance' => ['required', 'string'],
            'meal_allowance_time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        $defaultStart = $this->parseField('default_start_time', fn () => Time::parseClock($data['default_start_time']));
        $netRate = $this->parseField('hourly_net_rate', fn () => Money::parseEuros($data['hourly_net_rate']));
        $threshold = $this->parseField('weekly_threshold', fn () => Time::parseDuration($data['weekly_threshold']));
        $meal = $this->parseField('meal_allowance', fn () => Money::parseEuros($data['meal_allowance']));
        $mealTime = $this->parseField('meal_allowance_time', fn () => Time::parseClock($data['meal_allowance_time']));
        if ($netRate <= 0) {
            throw ValidationException::withMessages(['hourly_net_rate' => 'Le taux horaire net doit être supérieur à 0 €.']);
        }

        SettingPeriod::query()->updateOrCreate(['effective_from' => $data['effective_from']], [
            'default_start_time_minutes' => $defaultStart,
            'hourly_net_rate_cents' => $netRate,
            'weekly_threshold_minutes' => $threshold,
            'meal_allowance_cents' => $meal,
            'meal_allowance_time_minutes' => $mealTime,
        ]);

        return redirect()->route('settings.index')->with('status', 'Paramètres enregistrés à partir du '.$data['effective_from'].'. L’historique antérieur reste inchangé.');
    }

    private function parseField(string $field, callable $parser): int
    {
        try {
            return (int) $parser();
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages([$field => $error->getMessage()]);
        }
    }
}
