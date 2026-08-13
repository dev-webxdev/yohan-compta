<?php

namespace App\Support;

use DateTimeInterface;

final class FrenchDate
{
    private const MONTHS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    private const DAYS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

    public static function monthYear(DateTimeInterface $date): string
    {
        return ucfirst(self::MONTHS[(int) $date->format('n')]).' '.$date->format('Y');
    }

    public static function month(int $month): string
    {
        return ucfirst(self::MONTHS[$month]);
    }

    public static function day(DateTimeInterface $date): string
    {
        return self::DAYS[(int) $date->format('N')];
    }
}
