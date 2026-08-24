<?php

namespace App\Http\Controllers;

use App\Models\WorkDay;
use App\Services\PlanningService;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PlanningController
{
    public function index(Request $request, PlanningService $planning): View
    {
        $mode = $request->query('view') === 'week' ? 'week' : 'month';
        $date = (string) $request->query('date', now()->format('Y-m-d'));
        abort_unless(DateRange::isDate($date), 404);

        return view('planning', $planning->build($mode, $date));
    }

    public function update(Request $request, SettingsService $settings, string $date): RedirectResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        $data = $request->validate([
            'planned' => ['nullable', 'regex:/^\d{1,2}(?::[0-5]\d)?$/'],
            'is_leave' => ['sometimes', 'boolean'],
            'return_view' => ['nullable', 'in:month,week'],
            'return_date' => ['nullable', 'date_format:Y-m-d'],
        ], ['planned.regex' => 'Heures prévues : format HH ou HH:MM attendu.']);

        try {
            $planned = isset($data['planned']) && $data['planned'] !== '' ? Time::parseDuration($data['planned']) : null;
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['planned' => $error->getMessage()]);
        }
        if ($planned !== null && $planned > 24 * 60) {
            throw ValidationException::withMessages(['planned' => 'Les heures prévues ne peuvent pas dépasser 24:00.']);
        }

        $isLeave = (bool) ($data['is_leave'] ?? false);
        $day = WorkDay::query()->whereDate('date', $date)->first();
        if (!$day && ($planned !== null || $isLeave)) {
            $day = WorkDay::query()->create([
                'date' => $date,
                'start_time_minutes' => $settings->forDate($date)->default_start_time_minutes,
            ]);
        }
        if ($day) {
            $payload = ['planned_minutes' => $planned, 'is_leave' => $isLeave];
            if ($isLeave) {
                $payload += [
                    'driving_minutes' => 0,
                    'warehouse_minutes' => 0,
                    'is_rest' => false,
                    'meal_allowance_mode' => 'auto',
                    'meal_allowance_forced_cents' => null,
                ];
            }
            $day->update($payload);
        }

        return redirect()->route('planning.index', [
            'view' => $data['return_view'] ?? 'month',
            'date' => $data['return_date'] ?? $date,
        ])->with('status', 'Planning mis à jour.');
    }
}
