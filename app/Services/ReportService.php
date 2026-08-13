<?php

namespace App\Services;

use App\Models\OvertimePayment;
use App\Models\WorkDay;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Support\Collection;

final class ReportService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function month(string $month): array
    {
        $base = $this->monthBase($month);
        $allocation = $this->allocationSnapshot();
        $paidAllocated = $allocation['by_month'][$month]['paid'] ?? 0;

        return $base + [
            'paid_allocated_cents' => $paidAllocated,
            'paid_received_cents' => (int) OvertimePayment::query()->whereBetween('payment_date', [$base['start']->format('Y-m-d'), $base['end']->format('Y-m-d')])->sum('amount_cents'),
            'remaining_cents' => max(0, $base['overtime_cents'] - $paidAllocated),
            'remaining_minutes_indicative' => $this->indicativeMinutes($base['overtime_minutes'], $base['overtime_cents'], max(0, $base['overtime_cents'] - $paidAllocated)),
        ];
    }

    private function monthBase(string $month): array
    {
        $start = new DateTimeImmutable($month.'-01');
        $end = $start->modify('last day of this month');
        $days = $this->workDaysBetween($start, $end);
        $weekIds = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $weekIds[WeekCalculator::weekId($cursor)] = true;
        }

        $weeks = [];
        $overtimeMinutesMonth = 0;
        $overtimeNumeratorMonth = 0;
        foreach (array_keys($weekIds) as $weekId) {
            $week = $this->week($weekId);
            $weeks[] = $week;
            foreach ($week['overtime_by_date'] as $date => $minutes) {
                if (!str_starts_with($date, $month) || $minutes <= 0) {
                    continue;
                }
                $overtimeMinutesMonth += $minutes;
                $overtimeNumeratorMonth += Money::wageNumerator($minutes, $this->settings->forDate($date)->hourly_rate_cents);
            }
        }

        $workedMinutes = 0;
        $mealCents = 0;
        $wageNumerator = 0;
        foreach ($days as $day) {
            $date = $day->date->format('Y-m-d');
            $worked = $day->driving_minutes + $day->warehouse_minutes;
            $setting = $this->settings->forDate($date);
            $workedMinutes += $worked;
            $mealCents += PayrollMath::mealAllowanceCents($day->end_time_minutes, $day->meal_allowance_mode, $day->meal_allowance_forced_cents, $setting->meal_allowance_time_minutes, $setting->meal_allowance_cents);
            $wageNumerator += Money::wageNumerator($worked, $setting->hourly_rate_cents);
        }

        $workPay = Money::numeratorToCents($wageNumerator);

        return [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'work_days' => $days->keyBy(fn (WorkDay $day) => $day->date->format('Y-m-d')),
            'weeks' => $weeks,
            'worked_minutes' => $workedMinutes,
            'overtime_minutes' => $overtimeMinutesMonth,
            'overtime_cents' => Money::numeratorToCents($overtimeNumeratorMonth),
            'meal_cents' => $mealCents,
            'normal_pay_cents' => max(0, $workPay - Money::numeratorToCents($overtimeNumeratorMonth)),
            'work_pay_cents' => $workPay,
            'theoretical_total_cents' => $workPay + $mealCents,
        ];
    }

    public function week(string $weekId): array
    {
        $start = new DateTimeImmutable($weekId);
        $end = $start->modify('+6 days');
        $days = $this->workDaysBetween($start, $end)->keyBy(fn (WorkDay $day) => $day->date->format('Y-m-d'));
        $minutesByDate = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->modify("+$i days")->format('Y-m-d');
            $day = $days->get($date);
            $minutesByDate[$date] = $day ? $day->driving_minutes + $day->warehouse_minutes : 0;
        }

        $threshold = $this->settings->forDate($weekId)->weekly_threshold_minutes;
        $result = WeekCalculator::calculate($weekId, $minutesByDate, $threshold);
        $numerator = 0;
        foreach ($result['overtime_by_date'] as $date => $minutes) {
            if ($minutes > 0) {
                $numerator += Money::wageNumerator($minutes, $this->settings->forDate($date)->hourly_rate_cents);
            }
        }

        return [
            'id' => $weekId,
            'start' => $start,
            'end' => $end,
            'total_minutes' => $result['total'],
            'overtime_minutes' => $result['overtime'],
            'overtime_by_date' => $result['overtime_by_date'],
            'overtime_cents' => Money::numeratorToCents($numerator),
        ];
    }

    public function year(int $year): array
    {
        $allocation = $this->allocationSnapshot();
        $months = [];
        $totals = [
            'worked_minutes' => 0,
            'overtime_minutes' => 0,
            'overtime_cents' => 0,
            'meal_cents' => 0,
            'paid_received_cents' => 0,
            'paid_allocated_cents' => 0,
            'remaining_cents' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $base = $this->monthBase($key);
            $paidAllocated = $allocation['by_month'][$key]['paid'] ?? 0;
            $item = $base + [
                'paid_allocated_cents' => $paidAllocated,
                'paid_received_cents' => (int) OvertimePayment::query()->whereBetween('payment_date', [$base['start']->format('Y-m-d'), $base['end']->format('Y-m-d')])->sum('amount_cents'),
                'remaining_cents' => max(0, $base['overtime_cents'] - $paidAllocated),
                'remaining_minutes_indicative' => $this->indicativeMinutes($base['overtime_minutes'], $base['overtime_cents'], max(0, $base['overtime_cents'] - $paidAllocated)),
            ];
            $months[$key] = $item;
            foreach ($totals as $field => $unused) {
                $totals[$field] += $item[$field];
            }
        }

        return ['year' => $year, 'months' => $months, 'totals' => $totals];
    }

    public function balance(): array
    {
        $snapshot = $this->allocationSnapshot();
        return [
            'generated' => $snapshot['generated'],
            'paid' => $snapshot['paid'],
            'remaining' => max(0, $snapshot['generated'] - $snapshot['paid']),
            'credit' => max(0, $snapshot['paid'] - $snapshot['generated']),
            'by_month' => $snapshot['by_month'],
            'remaining_minutes_indicative' => array_sum(array_column($snapshot['by_month'], 'remaining_minutes_indicative')),
        ];
    }

    public function paymentAllocations(): array
    {
        return $this->allocationSnapshot()['by_payment'];
    }

    private function allocationSnapshot(): array
    {
        $firstDate = WorkDay::query()->min('date');
        $lastEligibleMonth = now()->format('Y-m');
        $debts = [];
        if ($firstDate) {
            $cursor = new DateTimeImmutable(substr((string) $firstDate, 0, 7).'-01');
            $end = new DateTimeImmutable($lastEligibleMonth.'-01');
            while ($cursor <= $end) {
                $month = $cursor->format('Y-m');
                $base = $this->monthBase($month);
                if ($base['overtime_cents'] > 0) {
                    $debts[$month] = ['generated' => $base['overtime_cents'], 'overtime_minutes' => $base['overtime_minutes']];
                }
                $cursor = $cursor->modify('+1 month');
            }
        }

        $payments = OvertimePayment::query()->whereDate('payment_date', '<=', now()->format('Y-m-d'))->orderBy('payment_date')->orderBy('id')->get(['id', 'amount_cents'])->map(fn (OvertimePayment $payment) => ['id' => $payment->id, 'amount_cents' => $payment->amount_cents])->all();
        return PaymentAllocator::allocate($debts, $payments);
    }

    private function indicativeMinutes(int $generatedMinutes, int $generatedCents, int $remainingCents): int
    {
        if ($generatedMinutes <= 0 || $generatedCents <= 0 || $remainingCents <= 0) {
            return 0;
        }
        return (int) round($generatedMinutes * ($remainingCents / $generatedCents));
    }

    private function workDaysBetween(DateTimeImmutable $start, DateTimeImmutable $end): Collection
    {
        return WorkDay::query()->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])->orderBy('date')->get();
    }
}
