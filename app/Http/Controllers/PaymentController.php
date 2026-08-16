<?php

namespace App\Http\Controllers;

use App\Models\OvertimePayment;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PaymentController
{
    public function index(Request $request, ReportService $reports, SettingsService $settings): View
    {
        $filterMonth = trim((string) $request->query('month', ''));
        $filterYear = $request->integer('year');
        if ($filterMonth !== '' && !DateRange::isMonth($filterMonth)) {
            abort(404);
        }
        if ($filterYear !== 0 && !DateRange::containsYear($filterYear)) {
            abort(404);
        }

        $query = OvertimePayment::query()->orderByDesc('payment_date')->orderByDesc('id');
        if ($filterMonth !== '') {
            $start = new \DateTimeImmutable($filterMonth.'-01');
            $query->whereBetween('payment_date', [$start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d')]);
        } elseif ($filterYear !== 0) {
            $query->whereBetween('payment_date', [$filterYear.'-01-01', $filterYear.'-12-31']);
        }

        $payments = $query->paginate(50)->withQueryString();
        $editingPayment = $request->integer('edit') > 0 ? OvertimePayment::query()->find($request->integer('edit')) : null;
        $availableMonths = OvertimePayment::query()
            ->selectRaw('substr(payment_date, 1, 7) as month')
            ->distinct()
            ->orderByDesc('month')
            ->pluck('month')
            ->all();
        $paymentHours = [];
        foreach ($payments as $payment) {
            $rate = $settings->forDate($payment->payment_date->format('Y-m-d'))->hourly_net_rate_cents;
            $paymentHours[$payment->id] = [
                'minutes' => $payment->hours_paid_minutes ?? (int) round($payment->amount_cents * 60 / max(1, $rate)),
                'indicative' => $payment->hours_paid_minutes === null,
            ];
        }

        return view('payments', [
            'payments' => $payments,
            'editingPayment' => $editingPayment,
            'paymentHours' => $paymentHours,
            'allocations' => $reports->paymentAllocations(),
            'balance' => $reports->balance(),
            'availableMonths' => $availableMonths,
            'filterMonth' => $filterMonth,
            'filterYear' => $filterYear,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $this->paymentPayload($request);
        OvertimePayment::query()->create($payload);

        return redirect()->route('payments.index')->with('status', 'Paiement enregistré. Les soldes nets sont recalculés automatiquement en FIFO.');
    }

    public function update(Request $request, OvertimePayment $payment): RedirectResponse
    {
        $payment->update($this->paymentPayload($request));

        return redirect()->route('payments.index')->with('status', 'Paiement modifié. Les soldes ont été recalculés automatiquement.');
    }

    public function destroy(OvertimePayment $payment): RedirectResponse
    {
        $payment->delete();
        return redirect()->route('payments.index')->with('status', 'Paiement supprimé. Les soldes ont été recalculés.');
    }

    /** @return array{payment_date:string,amount_cents:int,hours_paid_minutes:?int,period_reference:?string} */
    private function paymentPayload(Request $request): array
    {
        $data = $request->validate([
            'payment_date' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:'.DateRange::MIN_DATE,
                'before_or_equal:'.DateRange::MAX_DATE,
            ],
            'amount' => ['required', 'string', 'max:30'],
            'hours_paid' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'period_reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $amountCents = Money::parseEuros($data['amount']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['amount' => $error->getMessage()]);
        }
        try {
            $hoursMinutes = !empty($data['hours_paid']) ? Time::parseDuration($data['hours_paid']) : null;
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['hours_paid' => $error->getMessage()]);
        }

        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Le paiement doit être supérieur à 0 €.']);
        }

        return [
            'payment_date' => $data['payment_date'],
            'amount_cents' => $amountCents,
            'hours_paid_minutes' => $hoursMinutes,
            'period_reference' => trim((string) ($data['period_reference'] ?? '')) ?: null,
        ];
    }
}
