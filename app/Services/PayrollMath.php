<?php

namespace App\Services;

final class PayrollMath
{
    public static function totalWorked(int $drivingMinutes, int $warehouseMinutes): int
    {
        $total = max(0, $drivingMinutes) + max(0, $warehouseMinutes);
        if ($total > 1440) {
            throw new \InvalidArgumentException('Le total travaillé ne peut pas dépasser 24:00.');
        }

        return $total;
    }

    public static function endTimeMinutes(int $startTimeMinutes, int $drivingMinutes, int $warehouseMinutes): int
    {
        return max(0, $startTimeMinutes) + self::totalWorked($drivingMinutes, $warehouseMinutes);
    }

    public static function mealAllowanceCents(?int $endTimeMinutes, string $mode, ?int $forcedCents, int $thresholdMinutes, int $defaultCents): int
    {
        if ($mode === 'forced') {
            return max(0, (int) $forcedCents);
        }

        return $endTimeMinutes !== null && $endTimeMinutes >= $thresholdMinutes ? $defaultCents : 0;
    }
}
