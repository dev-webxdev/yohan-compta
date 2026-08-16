<?php

namespace App\Services;

final class PaymentAllocator
{
    /**
     * @param array<string,array{generated:int,overtime_minutes:int}> $debts Ordered oldest to newest.
     * @param array<int,array{id:int,amount_cents:int,hours_paid_minutes?:?int}> $payments Ordered by payment date then id.
     * @return array{generated:int,paid:int,by_month:array<string,array{generated:int,paid:int,remaining:int,overtime_minutes:int,remaining_minutes_indicative:int}>,by_payment:array<int,array<int,array{month:string,amount_cents:int}>>}
     */
    public static function allocate(array $debts, array $payments): array
    {
        $byMonth = [];
        $implicitPaidCents = [];
        $explicitPaidMinutes = [];
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
            $implicitPaidCents[$month] = 0;
            $explicitPaidMinutes[$month] = 0;
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

            if (array_key_exists('hours_paid_minutes', $payment) && $payment['hours_paid_minutes'] !== null) {
                $remainingMinutes = max(0, (int) $payment['hours_paid_minutes']);
                foreach ($byMonth as $month => &$debt) {
                    if ($remainingMinutes <= 0) {
                        break;
                    }
                    $allocatedMinutes = min($remainingMinutes, $debt['remaining_minutes_indicative']);
                    $explicitPaidMinutes[$month] += $allocatedMinutes;
                    $debt['remaining_minutes_indicative'] -= $allocatedMinutes;
                    $remainingMinutes -= $allocatedMinutes;
                }
                unset($debt);
                continue;
            }

            foreach ($byPayment[$paymentId] ?? [] as $allocation) {
                $month = $allocation['month'];
                $debt = &$byMonth[$month];
                $implicitPaidCents[$month] += $allocation['amount_cents'];
                $debt['remaining_minutes_indicative'] = max(0, self::indicativeMinutes(
                    $debt['overtime_minutes'],
                    $debt['generated'],
                    max(0, $debt['generated'] - $implicitPaidCents[$month]),
                ) - $explicitPaidMinutes[$month]);
                unset($debt);
            }
        }

        return [
            'generated' => $generated,
            'paid' => $paid,
            'by_month' => $byMonth,
            'by_payment' => $byPayment,
        ];
    }

    private static function indicativeMinutes(int $generatedMinutes, int $generatedCents, int $cents): int
    {
        if ($generatedMinutes <= 0 || $generatedCents <= 0 || $cents <= 0) {
            return 0;
        }

        return (int) round($generatedMinutes * ($cents / $generatedCents));
    }
}
