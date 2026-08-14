<?php

namespace App\Support;

use InvalidArgumentException;

final class Time
{
    public static function parseDuration(?string $value): int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return 0;
        }

        if (!preg_match('/^(\d{1,3})(?::([0-5]\d))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Durée invalide. Format attendu : HH ou HH:MM.');
        }

        return ((int) $matches[1] * 60) + (int) ($matches[2] ?? 0);
    }

    public static function parseClock(?string $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^([01]?\d|2[0-3])(?::([0-5]\d))?$/', $value, $matches)) {
            throw new InvalidArgumentException('Heure invalide. Format attendu : HH ou HH:MM.');
        }

        return ((int) $matches[1] * 60) + (int) ($matches[2] ?? 0);
    }

    public static function formatDuration(int $minutes): string
    {
        $minutes = max(0, $minutes);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    public static function formatClock(?int $minutes): string
    {
        if ($minutes === null) {
            return '';
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
