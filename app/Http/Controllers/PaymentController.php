<?php

namespace App\Http\Controllers;

use App\Models\OvertimePayment;
use App\Services\DatabaseMaintenanceService;
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
    public function index(Request $request, ReportService $reports, SettingsService $settings): View
    {
        $payments = OvertimePayment::query()->orderByDesc('payment_date')->orderByDesc('id')->get();
        $editingPayment = $request->integer('edit') > 0 ? OvertimePayment::query()->find($request->integer('edit')) : null;
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
        ]);
    }

    public function store(Request $request, ReportService $reports, SettingsService $settings, DatabaseMaintenanceService $database): RedirectResponse
    {
        $payload = $this->paymentPayload($request, $reports, $settings);
        OvertimePayment::query()->create($payload);
        $database->refreshAutomaticBackup();

        return redirect()->route('payments.index')->with('status', 'Paiement enregistré. Les soldes nets sont recalculés automatiquement en FIFO.');
    }

    public function update(Request $request, OvertimePayment $payment, ReportService $reports, SettingsService $settings, DatabaseMaintenanceService $database): RedirectResponse
    {
        $payment->update($this->paymentPayload($request, $reports, $settings, $payment));
        $database->refreshAutomaticBackup();

        return redirect()->route('payments.index')->with('status', 'Paiement modifié. Les soldes ont été recalculés automatiquement.');
    }

    public function destroy(OvertimePayment $payment, DatabaseMaintenanceService $database): RedirectResponse
    {
        $payment->delete();
        $database->refreshAutomaticBackup();
        return redirect()->route('payments.index')->with('status', 'Paiement supprimé. Les soldes ont été recalculés.');
    }

    /** @return array{payment_date:string,amount_cents:int,hours_paid_minutes:?int,note:?string,period_reference:?string} */
    private function paymentPayload(Request $request, ReportService $reports, SettingsService $settings, ?OvertimePayment $payment = null): array
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:30'],
            'hours_paid' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'period_reference' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
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

        if ($hoursMinutes !== null) {
            $remainingMinutes = (int) $reports->balance()['remaining_minutes_indicative'];
            $existingMinutes = 0;
            if ($payment !== null) {
                $rate = $settings->forDate($payment->payment_date->format('Y-m-d'))->hourly_net_rate_cents;
                $existingMinutes = $payment->hours_paid_minutes
                    ?? (int) round($payment->amount_cents * 60 / max(1, $rate));
            }
            $maximumMinutes = $remainingMinutes + $existingMinutes;
            if ($hoursMinutes > $maximumMinutes) {
                throw ValidationException::withMessages([
                    'hours_paid' => 'Les heures supplémentaires payées ne peuvent pas dépasser les '.Time::formatDuration($maximumMinutes).' d’heures supplémentaires restantes à payer.',
                ]);
            }
        }

        return [
            'payment_date' => $data['payment_date'],
            'amount_cents' => $amountCents,
            'hours_paid_minutes' => $hoursMinutes,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'period_reference' => trim((string) ($data['period_reference'] ?? '')) ?: null,
        ];
    }
}
