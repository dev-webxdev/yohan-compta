<?php

namespace App\Services;

use App\Models\MonthlySalary;
use App\Models\OvertimePayment;
use App\Support\FrenchDate;
use DateTimeImmutable;

final class SalaryReportService
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** @return array<string,mixed> */
    public function build(?int $year, ?string $from, ?string $to): array
    {
        $customPeriod = $from !== null || $to !== null;
        if ($customPeriod) {
            $start = $from ?? MonthlySalary::query()->min('month') ?? now()->format('Y-m');
            $end = $to ?? MonthlySalary::query()->max('month') ?? now()->format('Y-m');
        } else {
            $year ??= (int) now()->format('Y');
            $start = sprintf('%04d-01', $year);
            $end = sprintf('%04d-12', $year);
        }

        $salaries = MonthlySalary::query()
            ->whereBetween('month', [$start, $end])
            ->orderBy('month')
            ->get();

        $yearReports = [];
        foreach ($salaries->pluck('month')->map(static fn (string $month): int => (int) substr($month, 0, 4))->unique() as $salaryYear) {
            $yearReports[$salaryYear] = $this->reports->year($salaryYear);
        }

        $paidHoursByMonth = $salaries->isEmpty() ? [] : $this->paidHoursByMonth($start, $end);
        $rows = [];
        $previousAmount = null;
        foreach ($salaries as $salary) {
            $salaryYear = (int) substr($salary->month, 0, 4);
            $monthReport = $yearReports[$salaryYear]['months'][$salary->month];
            $paidHours = $paidHoursByMonth[$salary->month] ?? ['minutes' => 0, 'indicative' => false, 'has_value' => false];
            $rows[] = [
                'salary' => $salary,
                'month' => $salary->month,
                'amount_cents' => $salary->net_amount_cents,
                'delta_cents' => $previousAmount === null ? null : $salary->net_amount_cents - $previousAmount,
                'worked_minutes' => $monthReport['worked_minutes'],
                'overtime_minutes' => $monthReport['overtime_minutes'],
                'overtime_net_cents' => $monthReport['overtime_net_cents'],
                'overtime_paid_cents' => $monthReport['paid_received_cents'],
                'overtime_paid_minutes' => $paidHours['minutes'],
                'overtime_paid_minutes_indicative' => $paidHours['indicative'],
                'has_overtime_paid_minutes' => $paidHours['has_value'],
            ];
            $previousAmount = $salary->net_amount_cents;
        }

        $amounts = array_column($rows, 'amount_cents');
        $count = count($amounts);
        $total = array_sum($amounts);
        $stats = [
            'count' => $count,
            'total_cents' => $total,
            'average_cents' => $count > 0 ? intdiv($total + intdiv($count, 2), $count) : 0,
            'min_cents' => $count > 0 ? min($amounts) : 0,
            'max_cents' => $count > 0 ? max($amounts) : 0,
            'period_change_cents' => $count > 1 ? $amounts[$count - 1] - $amounts[0] : null,
        ];

        $years = MonthlySalary::query()
            ->selectRaw('CAST(substr(month, 1, 4) AS INTEGER) AS salary_year')
            ->distinct()
            ->orderByDesc('salary_year')
            ->pluck('salary_year')
            ->map(static fn ($value): int => (int) $value)
            ->push((int) now()->format('Y'));
        if ($year !== null) {
            $years->push($year);
        }
        $years = $years->unique()->sortDesc()->values()->all();

        return [
            'rows' => $rows,
            'stats' => $stats,
            'chart' => $this->chart($rows),
            'filters' => [
                'year' => $customPeriod ? null : $year,
                'from' => $customPeriod ? $start : null,
                'to' => $customPeriod ? $end : null,
                'custom_period' => $customPeriod,
            ],
            'available_years' => $years,
        ];
    }

    /** @return array<string,array{minutes:int,indicative:bool,has_value:bool}> */
    private function paidHoursByMonth(string $startMonth, string $endMonth): array
    {
        $startDate = $startMonth.'-01';
        $endDate = (new DateTimeImmutable($endMonth.'-01'))->modify('last day of this month')->format('Y-m-d');
        $allocations = $this->reports->paymentHourAllocations();
        $payments = OvertimePayment::query()
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->orderBy('payment_date')
            ->orderBy('id')
            ->get();

        $result = [];
        foreach ($payments as $payment) {
            if ($payment->payment_date->isFuture()) {
                continue;
            }

            $month = $payment->payment_date->format('Y-m');
            $indicative = $payment->hours_paid_minutes === null;
            $minutes = $payment->hours_paid_minutes;
            if ($minutes === null) {
                $minutes = array_sum(array_column($allocations[$payment->id] ?? [], 'minutes'));
            }

            $result[$month] ??= ['minutes' => 0, 'indicative' => false, 'has_value' => false];
            if ($minutes > 0) {
                $result[$month]['minutes'] += $minutes;
                $result[$month]['has_value'] = true;
            }
            $result[$month]['indicative'] = $result[$month]['indicative'] || $indicative;
        }

        return $result;
    }

    /** @param list<array<string,mixed>> $rows @return array<string,mixed> */
    private function chart(array $rows): array
    {
        if ($rows === []) {
            return ['points' => [], 'polyline' => '', 'min_cents' => 0, 'max_cents' => 0];
        }

        $amounts = array_column($rows, 'amount_cents');
        $minimum = min($amounts);
        $maximum = max($amounts);
        $range = max(1, $maximum - $minimum);
        $count = count($rows);
        $points = [];

        foreach ($rows as $index => $row) {
            $x = $count === 1 ? 500 : 60 + (880 * $index / ($count - 1));
            $y = 245 - (185 * (($row['amount_cents'] - $minimum) / $range));
            $points[] = [
                'x' => round($x, 2),
                'y' => round($y, 2),
                'month' => $row['month'],
                'label' => FrenchDate::month((int) substr($row['month'], 5, 2)).' '.substr($row['month'], 0, 4),
                'short_label' => substr($row['month'], 5, 2).'/'.substr($row['month'], 2, 2),
                'amount_cents' => $row['amount_cents'],
            ];
        }

        return [
            'points' => $points,
            'polyline' => implode(' ', array_map(static fn (array $point): string => $point['x'].','.$point['y'], $points)),
            'min_cents' => $minimum,
            'max_cents' => $maximum,
        ];
    }
}
