<?php

namespace App\Services;

use App\Models\MonthlySalary;
use App\Support\FrenchDate;

final class SalaryReportService
{
    /** @return array<string,mixed> */
    public function build(): array
    {
        $salaries = MonthlySalary::query()->orderBy('month')->get();
        $amounts = $salaries->pluck('net_amount_cents')->map(static fn ($value): int => (int) $value)->all();
        $count = count($amounts);
        $total = array_sum($amounts);

        return [
            'rows' => $salaries->reverse()->values(),
            'stats' => [
                'average_cents' => $count > 0 ? intdiv($total + intdiv($count, 2), $count) : 0,
                'min_cents' => $count > 0 ? min($amounts) : 0,
                'max_cents' => $count > 0 ? max($amounts) : 0,
            ],
            'chart' => $this->chart($salaries->take(-12)->values()->all(), $count),
        ];
    }

    /** @param list<MonthlySalary> $salaries @return array<string,mixed> */
    private function chart(array $salaries, int $totalCount): array
    {
        if ($salaries === []) {
            return ['points' => [], 'polyline' => '', 'area' => '', 'min_cents' => 0, 'max_cents' => 0, 'total_count' => $totalCount];
        }

        $amounts = array_map(static fn (MonthlySalary $salary): int => $salary->net_amount_cents, $salaries);
        $minimum = min($amounts);
        $maximum = max($amounts);
        $range = $maximum - $minimum;
        $count = count($salaries);
        $points = [];

        foreach ($salaries as $index => $salary) {
            $x = $count === 1 ? 500 : 35 + (930 * $index / ($count - 1));
            $y = $range === 0 ? 96 : 165 - (140 * (($salary->net_amount_cents - $minimum) / $range));
            $points[] = [
                'x' => round($x, 2),
                'y' => round($y, 2),
                'month' => $salary->month,
                'label' => FrenchDate::month((int) substr($salary->month, 5, 2)).' '.substr($salary->month, 0, 4),
                'short_label' => substr($salary->month, 5, 2).'/'.substr($salary->month, 2, 2),
                'amount_cents' => $salary->net_amount_cents,
            ];
        }

        $polyline = implode(' ', array_map(static fn (array $point): string => $point['x'].','.$point['y'], $points));
        $area = $points[0]['x'].',178 '.$polyline.' '.$points[array_key_last($points)]['x'].',178';

        return [
            'points' => $points,
            'polyline' => $polyline,
            'area' => $area,
            'min_cents' => $minimum,
            'max_cents' => $maximum,
            'total_count' => $totalCount,
        ];
    }
}
