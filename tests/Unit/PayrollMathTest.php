<?php

namespace Tests\Unit;

use App\Services\PayrollMath;
use App\Support\Money;
use PHPUnit\Framework\TestCase;

final class PayrollMathTest extends TestCase
{
    public function test_simple_day(): void
    {
        $worked = PayrollMath::totalWorked(315, 60);
        self::assertSame(375, $worked);
        self::assertSame(855, PayrollMath::endTimeMinutes(465, 330, 60));
    }

    public function test_day_cannot_exceed_24_hours(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PayrollMath::totalWorked(1000, 500);
    }

    public function test_meal_threshold_and_forced_mode(): void
    {
        self::assertSame(0, PayrollMath::mealAllowanceCents(854, 60, 'auto', null, 855, 1600));
        self::assertSame(1600, PayrollMath::mealAllowanceCents(855, 60, 'auto', null, 855, 1600));
        self::assertSame(1600, PayrollMath::mealAllowanceCents(870, 60, 'auto', null, 855, 1600));
        self::assertSame(0, PayrollMath::mealAllowanceCents(900, 0, 'auto', null, 855, 1600));
        self::assertSame(750, PayrollMath::mealAllowanceCents(600, 0, 'forced', 750, 855, 1600));
    }

    public function test_money_keeps_precision_until_display(): void
    {
        self::assertSame(55395, Money::wageNumerator(45, 1231));
        self::assertSame(923, Money::numeratorToCents(55395));
    }
}
