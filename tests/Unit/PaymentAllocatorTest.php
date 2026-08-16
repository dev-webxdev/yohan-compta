<?php

namespace Tests\Unit;

use App\Services\PaymentAllocator;
use PHPUnit\Framework\TestCase;

final class PaymentAllocatorTest extends TestCase
{
    public function test_partial_and_multiple_payments_are_fifo(): void
    {
        $debts = [
            '2026-07' => ['generated' => 10000, 'overtime_minutes' => 600],
            '2026-08' => ['generated' => 8000, 'overtime_minutes' => 480],
        ];
        $result = PaymentAllocator::allocate($debts, [
            ['id' => 1, 'amount_cents' => 6000],
            ['id' => 2, 'amount_cents' => 7000],
        ]);

        self::assertSame(18000, $result['generated']);
        self::assertSame(13000, $result['paid']);
        self::assertSame(0, $result['by_month']['2026-07']['remaining']);
        self::assertSame(5000, $result['by_month']['2026-08']['remaining']);
        self::assertSame(4000, $result['by_payment'][2][0]['amount_cents']);
        self::assertSame(3000, $result['by_payment'][2][1]['amount_cents']);
    }

    public function test_overpayment_becomes_credit_without_negative_debt(): void
    {
        $result = PaymentAllocator::allocate(['2026-08' => ['generated' => 5000, 'overtime_minutes' => 240]], [['id' => 1, 'amount_cents' => 5500]]);
        self::assertSame(0, $result['by_month']['2026-08']['remaining']);
        self::assertSame(500, $result['paid'] - $result['generated']);
        self::assertSame(0, $result['by_month']['2026-08']['remaining_minutes_indicative']);
    }

    public function test_explicit_paid_hours_are_independent_from_payment_amount(): void
    {
        $result = PaymentAllocator::allocate(
            ['2026-08' => ['generated' => 30000, 'overtime_minutes' => 1635]],
            [['id' => 1, 'amount_cents' => 10000, 'hours_paid_minutes' => 60]],
        );

        self::assertSame(20000, $result['by_month']['2026-08']['remaining']);
        self::assertSame(1575, $result['by_month']['2026-08']['remaining_minutes_indicative']);
    }

    public function test_explicit_hours_follow_fifo_across_months(): void
    {
        $result = PaymentAllocator::allocate([
            '2026-07' => ['generated' => 2000, 'overtime_minutes' => 120],
            '2026-08' => ['generated' => 3000, 'overtime_minutes' => 180],
        ], [[
            'id' => 1,
            'amount_cents' => 5000,
            'hours_paid_minutes' => 150,
        ]]);

        self::assertSame(0, $result['by_month']['2026-07']['remaining_minutes_indicative']);
        self::assertSame(150, $result['by_month']['2026-08']['remaining_minutes_indicative']);
    }

    public function test_reallocation_is_deterministic_after_retroactive_debt_change(): void
    {
        $payments = [['id' => 1, 'amount_cents' => 6000]];
        $before = PaymentAllocator::allocate([
            '2026-07' => ['generated' => 5000, 'overtime_minutes' => 240],
            '2026-08' => ['generated' => 5000, 'overtime_minutes' => 240],
        ], $payments);
        $after = PaymentAllocator::allocate([
            '2026-07' => ['generated' => 2000, 'overtime_minutes' => 96],
            '2026-08' => ['generated' => 5000, 'overtime_minutes' => 240],
        ], $payments);

        self::assertSame(1000, $before['by_payment'][1][1]['amount_cents']);
        self::assertSame(4000, $after['by_payment'][1][1]['amount_cents']);
        self::assertSame(1000, $after['by_month']['2026-08']['remaining']);
    }
}
