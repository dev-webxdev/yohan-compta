<?php

namespace App\Http\Controllers;

use App\Models\DocumentLink;
use App\Models\OvertimePayment;
use App\Services\DocumentLinkService;
use App\Services\ReportService;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class PaymentController
{
    public function index(ReportService $reports, DocumentLinkService $links): View
    {
        $payments = OvertimePayment::query()->orderByDesc('payment_date')->orderByDesc('id')->paginate(50);
        $hourAllocations = $reports->paymentHourAllocations();

        $paymentHours = [];
        foreach ($payments as $payment) {
            $allocatedMinutes = array_sum(array_column($hourAllocations[$payment->id] ?? [], 'minutes'));
            $paymentHours[$payment->id] = [
                'minutes' => $payment->hours_paid_minutes ?? ($allocatedMinutes > 0 ? $allocatedMinutes : null),
                'indicative' => $payment->hours_paid_minutes === null,
            ];
        }

        return view('payments', [
            'payments' => $payments,
            'paymentHours' => $paymentHours,
            'balance' => $reports->balance(),
            'documentCounts' => $links->counts('payment', $payments->pluck('id')->map(fn ($id): int => (int) $id)->all()),
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
        DocumentLink::query()->where('target_type', 'payment')->where('target_key', (string) $payment->id)->delete();
        $payment->delete();
        return redirect()->route('payments.index')->with('status', 'Paiement supprimé. Les soldes ont été recalculés.');
    }

    /** @return array{payment_date:string,amount_cents:int,hours_paid_minutes:?int} */
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
        ];
    }
}
