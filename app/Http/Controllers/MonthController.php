<?php

namespace App\Http\Controllers;

use App\Models\OvertimePayment;
use App\Services\PayrollMath;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\FrenchDate;
use DateTimeImmutable;
use Illuminate\View\View;

final class MonthController
{
    public function __invoke(ReportService $reports, SettingsService $settings, ?string $month = null): View
    {
        $month ??= now()->format('Y-m');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || (int) substr($month, 0, 4) < 2000 || (int) substr($month, 0, 4) > 2200) {
            abort(404);
        }

        $report = $reports->month($month);
        $calendarDays = [];
        $cursor = $report['start'];
        while ($cursor <= $report['end']) {
            $date = $cursor->format('Y-m-d');
            $workDay = $report['work_days']->get($date);
            $setting = $settings->forDate($date);
            $worked = $workDay ? $workDay->driving_minutes + $workDay->warehouse_minutes : 0;
            $calendarDays[] = [
                'date' => $cursor,
                'work_day' => $workDay,
                'worked' => $worked,
                'rest' => PayrollMath::restMinutes($worked),
                'meal' => $workDay ? PayrollMath::mealAllowanceCents(
                    $workDay->end_time_minutes,
                    $workDay->meal_allowance_mode,
                    $workDay->meal_allowance_forced_cents,
                    $setting->meal_allowance_time_minutes,
                    $setting->meal_allowance_cents,
                ) : 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $current = new DateTimeImmutable($month.'-01');

        return view('month', [
            'report' => $report,
            'calendarDays' => $calendarDays,
            'balance' => $reports->balance(),
            'previousMonth' => $current->modify('-1 month')->format('Y-m'),
            'nextMonth' => $current->modify('+1 month')->format('Y-m'),
            'currentMonth' => now()->format('Y-m'),
            'monthLabel' => FrenchDate::monthYear($current),
            'recentPayments' => OvertimePayment::query()
                ->whereDate('payment_date', '<=', now()->format('Y-m-d'))
                ->orderByDesc('payment_date')
                ->orderByDesc('id')
                ->limit(2)
                ->get(),
        ]);
    }
}
