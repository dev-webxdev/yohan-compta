<?php

namespace App\Support;

use DateTimeImmutable;

final class DateRange
{
    public const MIN_YEAR = 2000;
    public const MAX_YEAR = 2200;
    public const MIN_DATE = '2000-01-01';
    public const MAX_DATE = '2200-12-31';

    public static function containsYear(int $year): bool
    {
        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR;
    }

    public static function isMonth(string $month): bool
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) === 1
            && self::containsYear((int) substr($month, 0, 4));
    }

    public static function isDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false
            && $parsed->format('Y-m-d') === $date
            && self::containsYear((int) $parsed->format('Y'));
    }
}
