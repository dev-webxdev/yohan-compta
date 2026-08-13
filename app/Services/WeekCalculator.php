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

    public static function weekId(string|DateTimeImmutable $date): string
    {
        return self::monday($date)->format('Y-m-d');
    }

    /**
     * @param array<string,int> $minutesByDate ISO date => worked minutes
     * @return array{total:int,overtime:int,overtime_by_date:array<string,int>}
     */
    public static function calculate(string $weekId, array $minutesByDate, int $thresholdMinutes = 2100): array
    {
        $monday = new DateTimeImmutable($weekId);
        $cumulative = 0;
        $overtimeByDate = [];

        for ($i = 0; $i < 7; $i++) {
            $date = $monday->modify("+$i days")->format('Y-m-d');
            $worked = max(0, (int) ($minutesByDate[$date] ?? 0));
            $before = max(0, $cumulative - $thresholdMinutes);
            $cumulative += $worked;
            $after = max(0, $cumulative - $thresholdMinutes);
            $overtimeByDate[$date] = max(0, $after - $before);
        }

        return [
            'total' => $cumulative,
            'overtime' => max(0, $cumulative - $thresholdMinutes),
            'overtime_by_date' => $overtimeByDate,
        ];
    }
}
