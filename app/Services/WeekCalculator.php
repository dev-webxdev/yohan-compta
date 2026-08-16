<?php

namespace App\Services;

use DateTimeImmutable;

final class WeekCalculator
{
    public static function monday(string|DateTimeImmutable $date): DateTimeImmutable
    {
        $date = $date instanceof DateTimeImmutable ? $date : new DateTimeImmutable($date);
        $dayOfWeek = (int) $date->format('N');

        return $date->modify('-'.($dayOfWeek - 1).' days')->setTime(0, 0);
    }

    public static function periodStart(string|DateTimeImmutable $date): DateTimeImmutable
    {
        return self::monday($date);
    }

    public static function periodEnd(string|DateTimeImmutable $date): DateTimeImmutable
    {
        return self::monday($date)->modify('+6 days');
    }

    public static function weekId(string|DateTimeImmutable $date): string
    {
        return self::periodStart($date)->format('Y-m-d');
    }

    /**
     * @param array<string,int> $minutesByDate ISO date => worked minutes
     * @return array{total:int,overtime:int,overtime_25:int,overtime_50:int,overtime_by_date:array<string,int>,overtime_25_by_date:array<string,int>,overtime_50_by_date:array<string,int>}
     */
    public static function calculate(string $weekId, array $minutesByDate, int $thresholdMinutes = 2100): array
    {
        $start = self::monday($weekId);
        $end = self::periodEnd($start);
        $cumulative = 0;
        $overtimeByDate = [];
        $overtime25ByDate = [];
        $overtime50ByDate = [];
        $firstTierMinutes = 8 * 60;
        $firstTierEnd = $thresholdMinutes + $firstTierMinutes;

        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $worked = max(0, (int) ($minutesByDate[$key] ?? 0));
            $before = max(0, $cumulative - $thresholdMinutes);
            $before25 = min($firstTierMinutes, $before);
            $before50 = max(0, $cumulative - $firstTierEnd);
            $cumulative += $worked;
            $after = max(0, $cumulative - $thresholdMinutes);
            $after25 = min($firstTierMinutes, $after);
            $after50 = max(0, $cumulative - $firstTierEnd);
            $overtimeByDate[$key] = max(0, $after - $before);
            $overtime25ByDate[$key] = max(0, $after25 - $before25);
            $overtime50ByDate[$key] = max(0, $after50 - $before50);
        }

        $overtime = max(0, $cumulative - $thresholdMinutes);

        return [
            'total' => $cumulative,
            'overtime' => $overtime,
            'overtime_25' => min($firstTierMinutes, $overtime),
            'overtime_50' => max(0, $overtime - $firstTierMinutes),
            'overtime_by_date' => $overtimeByDate,
            'overtime_25_by_date' => $overtime25ByDate,
            'overtime_50_by_date' => $overtime50ByDate,
        ];
    }
}
