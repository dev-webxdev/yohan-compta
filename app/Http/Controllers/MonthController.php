<?php

namespace App\Http\Controllers;

use App\Services\AnomalyService;
use App\Services\DocumentLinkService;
use App\Services\PayrollMath;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Services\WeekCalculator;
use App\Support\DateRange;
use App\Support\FrenchDate;
use DateTimeImmutable;
use Illuminate\View\View;

final class MonthController
{
    public function __invoke(
        ReportService $reports,
        SettingsService $settings,
        AnomalyService $anomalies,
        DocumentLinkService $links,
        ?string $month = null,
    ): View
    {
        $month ??= now()->format('Y-m');
        abort_unless(DateRange::isMonth($month), 404);

        $report = $reports->month($month);
        $calendarDays = [];
        $cursor = $report['start'];
        while ($cursor <= $report['end']) {
            $date = $cursor->format('Y-m-d');
            $workDay = $report['work_days']->get($date);
            $setting = $settings->forDate($date);
            $isRest = $workDay ? $workDay->is_rest : (int) $cursor->format('N') === 7;
            $isLeave = (bool) ($workDay?->is_leave ?? false);
            $start = $workDay?->start_time_minutes ?? $setting->default_start_time_minutes;
            $driving = $workDay?->driving_minutes ?? 0;
            $warehouse = $workDay?->warehouse_minutes ?? 0;
            $worked = $isRest || $isLeave ? 0 : $driving + $warehouse;
            $end = PayrollMath::endTimeMinutes($start, $driving, $warehouse);
            $calendarDays[] = [
                'date' => $cursor,
                'work_day' => $workDay,
                'is_locked' => $date < now()->format('Y-m-d'),
                'is_rest' => $isRest,
                'is_leave' => $isLeave,
                'planned' => $workDay?->planned_minutes,
                'needs_fill' => !$isRest && !$isLeave && $worked === 0,
                'start' => $start,
                'end' => $end,
                'worked' => $worked,
                'meal_default' => $setting->meal_allowance_cents,
                'meal_threshold' => $setting->meal_allowance_time_minutes,
                'meal' => $workDay && !$isRest ? PayrollMath::mealAllowanceCents(
                    $end,
                    $worked,
                    $workDay->meal_allowance_mode,
                    $workDay->meal_allowance_forced_cents,
                    $setting->meal_allowance_time_minutes,
                    $setting->meal_allowance_cents,
                ) : 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $current = new DateTimeImmutable($month.'-01');
        $dates = array_map(fn (array $row): string => $row['date']->format('Y-m-d'), $calendarDays);
        $weekKeys = array_values(array_unique(array_map(
            fn (array $week): string => WeekCalculator::monday($week['start'])->format('Y-m-d'),
            $report['weeks'],
        )));
        $documentCounts = [
            'month' => (int) (($links->counts('month', [$month]))[$month] ?? 0),
            'week' => $links->counts('week', $weekKeys),
            'day' => $links->counts('day', $dates),
        ];

        return view('month', [
            'report' => $report,
            'calendarDays' => $calendarDays,
            'balance' => $reports->balance(),
            'previousMonth' => $current->modify('-1 month')->format('Y-m'),
            'nextMonth' => $current->modify('+1 month')->format('Y-m'),
            'monthLabel' => FrenchDate::monthYear($current),
            'isFutureMonth' => $month > now()->format('Y-m'),
            'anomalies' => $anomalies->forMonth($month),
            'documentCounts' => $documentCounts,
        ]);
    }
}
