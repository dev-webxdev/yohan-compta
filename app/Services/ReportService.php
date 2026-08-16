<?php

namespace App\Services;

use App\Models\OvertimePayment;
use App\Models\WorkDay;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Support\Collection;

final class ReportService
{
    /** @var array<string,array<string,mixed>> */
    private array $monthBaseCache = [];
    /** @var array<string,mixed>|null */
    private ?array $allocationCache = null;
    private int $calculationDepth = 0;

    public function __construct(private readonly SettingsService $settings)
    {
    }

    /** @return array<string,mixed> */
    public function month(string $month): array
    {
        $this->beginCalculation();
        try {
        $base = $this->monthBase($month);
        $allocation = $this->allocationSnapshot();
        $paidAllocated = $allocation['by_month'][$month]['paid'] ?? 0;
        $remaining = max(0, $base['overtime_net_cents'] - $paidAllocated);

        return $base + [
            'paid_allocated_cents' => $paidAllocated,
            'paid_received_cents' => (int) OvertimePayment::query()
                ->whereBetween('payment_date', [$base['start']->format('Y-m-d'), $base['end']->format('Y-m-d')])
                ->sum('amount_cents'),
            'remaining_cents' => $remaining,
            'remaining_minutes_indicative' => $allocation['by_month'][$month]['remaining_minutes_indicative'] ?? 0,
        ];
        } finally {
            $this->endCalculation();
        }
    }

    /** @return array<string,mixed> */
    private function monthBase(string $month): array
    {
        if (isset($this->monthBaseCache[$month])) {
            return $this->monthBaseCache[$month];
        }

        $start = new DateTimeImmutable($month.'-01');
        $end = $start->modify('last day of this month');
        $days = $this->workDaysBetween($start, $end);
        $weekIds = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $weekIds[WeekCalculator::weekId($cursor)] = true;
        }

        $weeks = [];
        $overtimeMinutesMonth = 0;
        $overtimeNetNumerator = 0;
        foreach (array_keys($weekIds) as $weekId) {
            $week = $this->week($weekId);
            $weeks[] = $week;
            foreach ($week['overtime_by_date'] as $date => $minutes) {
                if (!str_starts_with($date, $month) || $minutes <= 0) {
                    continue;
                }
                $setting = $this->settings->forDate($date);
                $overtimeMinutesMonth += $minutes;
                $overtimeNetNumerator += Money::wageNumerator($minutes, $setting->hourly_net_rate_cents);
            }
        }

        $workedMinutes = 0;
        $mealCents = 0;
        $netNumerator = 0;
        foreach ($days as $day) {
            if ($day->is_rest) {
                continue;
            }

            $date = $day->date->format('Y-m-d');
            $worked = $day->driving_minutes + $day->warehouse_minutes;
            $setting = $this->settings->forDate($date);
            $endTime = PayrollMath::endTimeMinutes($day->start_time_minutes, $day->driving_minutes, $day->warehouse_minutes);

            $workedMinutes += $worked;
            $mealCents += PayrollMath::mealAllowanceCents(
                $endTime,
                $day->meal_allowance_mode,
                $day->meal_allowance_forced_cents,
                $setting->meal_allowance_time_minutes,
                $setting->meal_allowance_cents,
            );
            $netNumerator += Money::wageNumerator($worked, $setting->hourly_net_rate_cents);
        }

        $workNet = Money::numeratorToCents($netNumerator);
        $overtimeNet = Money::numeratorToCents($overtimeNetNumerator);
        $normalNet = max(0, $workNet - $overtimeNet);

        return $this->monthBaseCache[$month] = [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'work_days' => $days->keyBy(fn (WorkDay $day) => $day->date->format('Y-m-d')),
            'weeks' => $weeks,
            'worked_minutes' => $workedMinutes,
            'overtime_minutes' => $overtimeMinutesMonth,
            'overtime_net_cents' => $overtimeNet,
            'meal_cents' => $mealCents,
            'normal_net_cents' => $normalNet,
            'work_net_cents' => $workNet,
            'theoretical_net_cents' => $normalNet + $mealCents,
        ];
    }

    /** @return array<string,mixed> */
    public function week(string $weekId): array
    {
        $this->beginCalculation();
        try {
        $start = new DateTimeImmutable($weekId);
        $end = WeekCalculator::periodEnd($start);
        $days = $this->workDaysBetween($start, $end)->keyBy(fn (WorkDay $day) => $day->date->format('Y-m-d'));
        $minutesByDate = [];
        for ($cursor = $start; $cursor <= $end; $cursor = $cursor->modify('+1 day')) {
            $date = $cursor->format('Y-m-d');
            $day = $days->get($date);
            $minutesByDate[$date] = $day && !$day->is_rest ? $day->driving_minutes + $day->warehouse_minutes : 0;
        }

        $threshold = $this->settings->forDate($weekId)->weekly_threshold_minutes;
        $result = WeekCalculator::calculate($weekId, $minutesByDate, $threshold);
        $netNumerator = 0;
        foreach ($result['overtime_by_date'] as $date => $minutes) {
            if ($minutes <= 0) {
                continue;
            }
            $setting = $this->settings->forDate($date);
            $netNumerator += Money::wageNumerator($minutes, $setting->hourly_net_rate_cents);
        }

        return [
            'id' => $weekId,
            'start' => $start,
            'end' => $end,
            'total_minutes' => $result['total'],
            'overtime_minutes' => $result['overtime'],
            'overtime_by_date' => $result['overtime_by_date'],
            'overtime_net_cents' => Money::numeratorToCents($netNumerator),
        ];
        } finally {
            $this->endCalculation();
        }
    }

    /** @return array<string,mixed> */
    public function year(int $year): array
    {
        $this->beginCalculation();
        try {
        $allocation = $this->allocationSnapshot();
        $months = [];
        $totals = [
            'worked_minutes' => 0,
            'overtime_minutes' => 0,
            'overtime_net_cents' => 0,
            'meal_cents' => 0,
            'paid_received_cents' => 0,
            'paid_allocated_cents' => 0,
            'remaining_cents' => 0,
            'remaining_minutes_indicative' => 0,
        ];

        for ($month = 1; $month <= 12; $month++) {
            $key = sprintf('%04d-%02d', $year, $month);
            $base = $this->monthBase($key);
            $paidAllocated = $allocation['by_month'][$key]['paid'] ?? 0;
            $remaining = max(0, $base['overtime_net_cents'] - $paidAllocated);
            $item = $base + [
                'paid_allocated_cents' => $paidAllocated,
                'paid_received_cents' => (int) OvertimePayment::query()
                    ->whereBetween('payment_date', [$base['start']->format('Y-m-d'), $base['end']->format('Y-m-d')])
                    ->sum('amount_cents'),
                'remaining_cents' => $remaining,
                'remaining_minutes_indicative' => $allocation['by_month'][$key]['remaining_minutes_indicative'] ?? 0,
            ];
            $months[$key] = $item;
            foreach ($totals as $field => $unused) {
                $totals[$field] += $item[$field];
            }
        }

        return ['year' => $year, 'months' => $months, 'totals' => $totals];
        } finally {
            $this->endCalculation();
        }
    }

    /** @return array{generated:int,paid:int,remaining:int,credit:int,by_month:array<string,array<string,int>>,remaining_minutes_indicative:int} */
    public function balance(): array
    {
        $this->beginCalculation();
        try {
        $snapshot = $this->allocationSnapshot();
        $remainingMinutes = array_sum(array_column($snapshot['by_month'], 'remaining_minutes_indicative'));

        return [
            'generated' => $snapshot['generated'],
            'paid' => $snapshot['paid'],
            'remaining' => max(0, $snapshot['generated'] - $snapshot['paid']),
            'credit' => max(0, $snapshot['paid'] - $snapshot['generated']),
            'by_month' => $snapshot['by_month'],
            'remaining_minutes_indicative' => $remainingMinutes,
        ];
        } finally {
            $this->endCalculation();
        }
    }

    /** @return array<int,array<int,array{month:string,amount_cents:int}>> */
    public function paymentAllocations(): array
    {
        $this->beginCalculation();
        try {
            return $this->allocationSnapshot()['by_payment'];
        } finally {
            $this->endCalculation();
        }
    }

    /** @return array{generated:int,paid:int,by_month:array<string,array{generated:int,paid:int,remaining:int}>,by_payment:array<int,array<int,array{month:string,amount_cents:int}>>} */
    private function allocationSnapshot(): array
    {
        if ($this->allocationCache !== null) {
            return $this->allocationCache;
        }

        $firstDate = WorkDay::query()->min('date');
        $lastEligibleMonth = now()->format('Y-m');
        $debts = [];

        if ($firstDate) {
            $cursor = new DateTimeImmutable(substr((string) $firstDate, 0, 7).'-01');
            $end = new DateTimeImmutable($lastEligibleMonth.'-01');
            while ($cursor <= $end) {
                $month = $cursor->format('Y-m');
                $base = $this->monthBase($month);
                if ($base['overtime_net_cents'] > 0) {
                    $debts[$month] = [
                        'generated' => $base['overtime_net_cents'],
                        'overtime_minutes' => $base['overtime_minutes'],
                    ];
                }
                $cursor = $cursor->modify('+1 month');
            }
        }

        $payments = OvertimePayment::query()
            ->whereDate('payment_date', '<=', now()->format('Y-m-d'))
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get(['id', 'amount_cents', 'hours_paid_minutes'])
            ->map(fn (OvertimePayment $payment) => [
                'id' => $payment->id,
                'amount_cents' => $payment->amount_cents,
                'hours_paid_minutes' => $payment->hours_paid_minutes,
            ])
            ->all();

        return $this->allocationCache = PaymentAllocator::allocate($debts, $payments);
    }

    private function workDaysBetween(DateTimeImmutable $start, DateTimeImmutable $end): Collection
    {
        return WorkDay::query()
            ->whereBetween('date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->orderBy('date')
            ->get();
    }

    private function beginCalculation(): void
    {
        if ($this->calculationDepth === 0) {
            $this->monthBaseCache = [];
            $this->allocationCache = null;
        }
        $this->calculationDepth++;
    }

    private function endCalculation(): void
    {
        $this->calculationDepth--;
    }
}
