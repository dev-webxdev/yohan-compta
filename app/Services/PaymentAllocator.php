<?php

namespace App\Services;

final class PaymentAllocator
{
    /**
     * @param array<string,array{generated:int,overtime_minutes:int}> $debts Ordered oldest to newest.
     * @param array<int,array{id:int,amount_cents:int}> $payments Ordered by payment date then id.
     * @return array{generated:int,paid:int,by_month:array<string,array{generated:int,paid:int,remaining:int,overtime_minutes:int,remaining_minutes_indicative:int}>,by_payment:array<int,array<int,array{month:string,amount_cents:int}>>}
     */
    public static function allocate(array $debts, array $payments): array
    {
        $byMonth = [];
        $generated = 0;
        foreach ($debts as $month => $debt) {
            $amount = max(0, (int) $debt['generated']);
            if ($amount === 0) {
                continue;
            }
            $minutes = max(0, (int) $debt['overtime_minutes']);
            $byMonth[$month] = [
                'generated' => $amount,
                'paid' => 0,
                'remaining' => $amount,
                'overtime_minutes' => $minutes,
                'remaining_minutes_indicative' => $minutes,
            ];
            $generated += $amount;
        }

        $paid = 0;
        $byPayment = [];
        foreach ($payments as $payment) {
            $paymentId = (int) $payment['id'];
            $remainingPayment = max(0, (int) $payment['amount_cents']);
            $paid += $remainingPayment;

            foreach ($byMonth as $month => &$debt) {
                if ($remainingPayment <= 0) {
                    break;
                }
                if ($debt['remaining'] <= 0) {
                    continue;
                }

                $allocated = min($remainingPayment, $debt['remaining']);
                $debt['paid'] += $allocated;
                $debt['remaining'] -= $allocated;
                $remainingPayment -= $allocated;
                $byPayment[$paymentId][] = ['month' => $month, 'amount_cents' => $allocated];
            }
            unset($debt);
        }

        foreach ($byMonth as &$debt) {
            $debt['remaining_minutes_indicative'] = self::indicativeMinutes(
                $debt['overtime_minutes'],
                $debt['generated'],
                $debt['remaining'],
            );
        }
        unset($debt);

        return [
            'generated' => $generated,
            'paid' => $paid,
            'by_month' => $byMonth,
            'by_payment' => $byPayment,
        ];
    }

    private static function indicativeMinutes(int $generatedMinutes, int $generatedCents, int $remainingCents): int
    {
        if ($generatedMinutes <= 0 || $generatedCents <= 0 || $remainingCents <= 0) {
            return 0;
        }

        return (int) round($generatedMinutes * ($remainingCents / $generatedCents));
    }
}
