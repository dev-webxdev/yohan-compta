<?php

namespace App\Http\Controllers;

use App\Models\SettingPeriod;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SettingsController
{
    public function index(SettingsService $settings): View
    {
        return view('settings', ['current' => $settings->forDate(now()->format('Y-m-d'))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'effective_from' => ['required', 'date'],
            'hourly_gross_rate' => ['required', 'string'],
            'hourly_net_rate' => ['required', 'string'],
            'weekly_threshold' => ['required', 'regex:/^\d{1,3}:[0-5]\d$/'],
            'meal_allowance' => ['required', 'string'],
            'meal_allowance_time' => ['required', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
        ]);

        try {
            $grossRate = Money::parseEuros($data['hourly_gross_rate']);
            $netRate = Money::parseEuros($data['hourly_net_rate']);
            $threshold = Time::parseDuration($data['weekly_threshold']);
            $meal = Money::parseEuros($data['meal_allowance']);
            $mealTime = Time::parseClock($data['meal_allowance_time']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['settings' => $e->getMessage()]);
        }

        SettingPeriod::query()->updateOrCreate(['effective_from' => $data['effective_from']], [
            'hourly_gross_rate_cents' => $grossRate,
            'hourly_net_rate_cents' => $netRate,
            'weekly_threshold_minutes' => $threshold,
            'meal_allowance_cents' => $meal,
            'meal_allowance_time_minutes' => $mealTime,
        ]);

        return redirect()->route('settings.index')->with('status', 'Paramètres enregistrés à partir du '.$data['effective_from'].'. L’historique antérieur reste inchangé.');
    }
}
