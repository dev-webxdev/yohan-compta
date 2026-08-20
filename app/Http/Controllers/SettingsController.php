<?php

namespace App\Http\Controllers;

use App\Models\SettingPeriod;
use App\Services\WeekCalculator;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SettingsController
{
    private const FIELDS = ['default_start_time_minutes', 'hourly_net_rate_cents', 'weekly_threshold_minutes', 'meal_allowance_cents', 'meal_allowance_time_minutes'];

    public function index(SettingsService $settings): View
    {
        return view('settings', [
            'current' => $settings->forDate(now()->format('Y-m-d')),
            'nextScheduled' => SettingPeriod::query()
                ->whereDate('effective_from', '>', now()->format('Y-m-d'))
                ->orderBy('effective_from')
                ->first(),
        ]);
    }

    public function values(Request $request, SettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.DateRange::MIN_DATE,
                'before_or_equal:'.DateRange::MAX_DATE,
            ],
        ]);
        $date = $data['date'];
        $period = $settings->forDate($date);
        $segmentStart = WeekCalculator::periodStart($date);
        $thresholdFrom = $segmentStart->format('Y-m-d') === $date
            ? $date
            : WeekCalculator::periodStart(WeekCalculator::periodEnd($date)->modify('+1 day'))->format('Y-m-d');

        return response()->json([
            'exact' => SettingPeriod::query()->whereDate('effective_from', $date)->exists(),
            'default_start_time' => Time::formatClock($period->default_start_time_minutes),
            'hourly_net_rate' => Money::formatInput($period->hourly_net_rate_cents),
            'weekly_threshold' => Time::formatDuration($period->weekly_threshold_minutes),
            'meal_allowance' => Money::formatInput($period->meal_allowance_cents),
            'meal_allowance_time' => Time::formatClock($period->meal_allowance_time_minutes),
            'weekly_threshold_effective_from' => $thresholdFrom,
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
        if ($threshold <= 0 || $threshold > 7 * 24 * 60) {
            throw ValidationException::withMessages(['weekly_threshold' => 'Le seuil hebdomadaire doit être compris entre 00:01 et 168:00.']);
        }

        $effectiveFrom = $data['effective_from'];
        $payload = [
            'default_start_time_minutes' => $defaultStart,
            'hourly_net_rate_cents' => $netRate,
            'weekly_threshold_minutes' => $threshold,
            'meal_allowance_cents' => $meal,
            'meal_allowance_time_minutes' => $mealTime,
        ];

        $propagatedPeriods = DB::transaction(function () use ($effectiveFrom, $payload): int {
            $existing = SettingPeriod::query()->whereDate('effective_from', $effectiveFrom)->first();
            $baseline = $existing ?? SettingPeriod::query()
                ->whereDate('effective_from', '<=', $effectiveFrom)
                ->orderByDesc('effective_from')
                ->firstOrFail();

            $changedFields = array_values(array_filter(
                self::FIELDS,
                static fn (string $field): bool => (int) $payload[$field] !== (int) $baseline->{$field},
            ));
            $futurePeriods = SettingPeriod::query()
                ->whereDate('effective_from', '>', $effectiveFrom)
                ->orderBy('effective_from')
                ->get();
            $futureUpdates = [];

            foreach ($changedFields as $field) {
                $expected = (int) $baseline->{$field};
                foreach ($futurePeriods as $future) {
                    if ((int) $future->{$field} !== $expected) {
                        break;
                    }
                    $futureUpdates[$future->id][$field] = $payload[$field];
                }
            }

            SettingPeriod::query()->updateOrCreate(['effective_from' => $effectiveFrom], $payload);

            foreach ($futureUpdates as $id => $update) {
                SettingPeriod::query()->whereKey($id)->update($update);
            }

            return count($futureUpdates);
        });

        $status = 'Paramètres enregistrés à partir du '.$effectiveFrom.'.';
        if ($propagatedPeriods > 0) {
            $status .= ' '.$propagatedPeriods.' changement'.($propagatedPeriods > 1 ? 's' : '').' futur'.($propagatedPeriods > 1 ? 's' : '').' compatible'.($propagatedPeriods > 1 ? 's' : '').' mis à jour pour éviter un retour arrière.';
        }

        return redirect()->route('settings.index')->with('status', $status);
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
