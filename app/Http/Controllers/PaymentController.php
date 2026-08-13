<?php

namespace App\Http\Controllers;

use App\Models\OvertimePayment;
use App\Services\PaymentService;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Money;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PaymentController
{
    public function index(ReportService $reports, SettingsService $settings): View
    {
        $payments = OvertimePayment::query()->orderByDesc('payment_date')->orderByDesc('id')->get();
        $paymentHours = [];
        foreach ($payments as $payment) {
            $rate = $settings->forDate($payment->payment_date->format('Y-m-d'))->hourly_rate_cents;
            $paymentHours[$payment->id] = [
                'minutes' => $payment->hours_paid_minutes ?? (int) round($payment->amount_cents * 60 / max(1, $rate)),
                'indicative' => $payment->hours_paid_minutes === null,
            ];
        }

        return view('payments', [
            'payments' => $payments,
            'paymentHours' => $paymentHours,
            'allocations' => $reports->paymentAllocations(),
            'balance' => $reports->balance(),
        ]);
    }

    public function store(Request $request, PaymentService $payments, SettingsService $settings): RedirectResponse
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'hours_paid' => ['nullable', 'regex:/^\d{1,3}:[0-5]\d$/'],
            'period_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'confirm_mismatch' => ['nullable', 'boolean'],
        ]);

        try {
            $amountCents = Money::parseEuros($data['amount']);
            $hoursMinutes = !empty($data['hours_paid']) ? Time::parseDuration($data['hours_paid']) : null;
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['amount' => $e->getMessage()]);
        }

        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['amount' => 'Le paiement doit être supérieur à 0 €.']);
        }

        if ($hoursMinutes !== null && empty($data['confirm_mismatch'])) {
            $rate = $settings->forDate($data['payment_date'])->hourly_rate_cents;
            $reference = Money::numeratorToCents($hoursMinutes * $rate);
            if (abs($reference - $amountCents) > 1) {
                return back()->withInput()->with('payment_warning', 'Le montant et les heures renseignées ne correspondent pas exactement au taux applicable à cette date. Vérifie puis coche la confirmation pour enregistrer quand même.');
            }
        }

        $payments->create($data['payment_date'], $amountCents, $hoursMinutes, trim((string) ($data['note'] ?? '')) ?: null, trim((string) ($data['period_reference'] ?? '')) ?: null);

        return redirect()->route('payments.index')->with('status', 'Paiement enregistré. Les soldes sont recalculés automatiquement en FIFO.');
    }

    public function destroy(OvertimePayment $payment): RedirectResponse
    {
        $payment->delete();
        return redirect()->route('payments.index')->with('status', 'Paiement supprimé. Les soldes ont été recalculés.');
    }
}
