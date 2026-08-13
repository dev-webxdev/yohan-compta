<?php

namespace Tests\Unit;

use App\Services\WeekCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WeekCalculatorTest extends TestCase
{
    #[DataProvider('weekTotals')]
    public function test_weekly_overtime(int $worked, int $expected): void
    {
        $dates = [];
        for ($i = 0; $i < 5; $i++) {
            $dates[(new \DateTimeImmutable('2026-08-03'))->modify("+$i days")->format('Y-m-d')] = intdiv($worked, 5) + ($i < $worked % 5 ? 1 : 0);
        }
        self::assertSame($expected, WeekCalculator::calculate('2026-08-03', $dates)['overtime']);
    }

    public static function weekTotals(): array
    {
        return [[1920, 0], [2100, 0], [2400, 300]];
    }

    public function test_week_is_cut_and_reset_at_month_boundary(): void
    {
        self::assertSame('2026-07-27', WeekCalculator::weekId('2026-07-31'));
        self::assertSame('2026-08-01', WeekCalculator::weekId('2026-08-01'));
        self::assertSame('2026-08-01', WeekCalculator::weekId('2026-08-02'));
        self::assertSame('2027-01-01', WeekCalculator::weekId('2027-01-03'));
        self::assertSame('2026-07-31', WeekCalculator::periodEnd('2026-07-27')->format('Y-m-d'));
        self::assertSame('2026-08-02', WeekCalculator::periodEnd('2026-08-01')->format('Y-m-d'));

        $minutes = [
            '2026-07-27' => 420, '2026-07-28' => 420, '2026-07-29' => 420, '2026-07-30' => 420,
            '2026-07-31' => 300, '2026-08-01' => 180, '2026-08-02' => 240,
        ];
        self::assertSame(0, WeekCalculator::calculate('2026-07-27', $minutes)['overtime']);
        self::assertSame(0, WeekCalculator::calculate('2026-08-01', $minutes)['overtime']);
    }
}
