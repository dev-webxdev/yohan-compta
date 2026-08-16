<?php

namespace App\Support;

final class Money
{
    public static function wageNumerator(int $minutes, int $hourlyRateCents): int
    {
        return $minutes * $hourlyRateCents;
    }

    public static function wagePercentNumerator(int $minutes, int $hourlyRateCents, int $percent): int
    {
        return $minutes * $hourlyRateCents * $percent;
    }

    public static function numeratorToCents(int $numerator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + 30, 60);
    }

    public static function percentNumeratorToCents(int $numerator): int
    {
        if ($numerator <= 0) {
            return 0;
        }

        return intdiv($numerator + 3000, 6000);
    }

    public static function formatCents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return $sign.number_format(intdiv($absolute, 100), 0, ',', ' ')
            .','.sprintf('%02d', $absolute % 100).' €';
    }

    public static function formatInput(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);
        $decimal = $absolute % 100;
        $value = $sign.(string) intdiv($absolute, 100);

        return $decimal === 0 ? $value : $value.','.rtrim(sprintf('%02d', $decimal), '0');
    }

    public static function parseEuros(string|int|float|null $value): int
    {
        $normalized = str_replace([' ', '€', ','], ['', '', '.'], trim((string) $value));
        if ($normalized === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $normalized)) {
            throw new \InvalidArgumentException('Montant invalide.');
        }

        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');
        $decimal = str_pad($decimal, 2, '0');

        return ((int) $whole * 100) + (int) substr($decimal, 0, 2);
    }
}
