<?php

namespace App\Http\Controllers;

use App\Models\MonthlySalary;
use App\Services\SalaryReportService;
use App\Support\DateRange;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SalaryController
{
    public function index(Request $request, SalaryReportService $reports): View
    {
        $filters = $request->validate([
            'year' => ['nullable', 'integer', 'between:'.DateRange::MIN_YEAR.','.DateRange::MAX_YEAR],
            'from' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'to' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'edit' => ['nullable', 'integer', 'min:1'],
        ]);

        foreach (['from', 'to'] as $field) {
            if (isset($filters[$field]) && !DateRange::isMonth($filters[$field])) {
                throw ValidationException::withMessages([$field => 'Période invalide.']);
            }
        }
        if (isset($filters['from'], $filters['to']) && $filters['from'] > $filters['to']) {
            throw ValidationException::withMessages(['to' => 'La fin de période doit être postérieure ou égale au début.']);
        }

        $editId = isset($filters['edit']) ? (int) $filters['edit'] : 0;

        return view('salaries', [
            'report' => $reports->build(
                isset($filters['year']) ? (int) $filters['year'] : null,
                $filters['from'] ?? null,
                $filters['to'] ?? null,
            ),
            'editingSalary' => $editId > 0 ? MonthlySalary::query()->find($editId) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MonthlySalary::query()->create($this->salaryPayload($request));

        return redirect()->route('salaries.index', ['year' => substr($request->string('month')->toString(), 0, 4)])
            ->with('status', 'Salaire enregistré.');
    }

    public function update(Request $request, MonthlySalary $salary): RedirectResponse
    {
        $salary->update($this->salaryPayload($request, $salary));

        return redirect()->route('salaries.index', ['year' => substr($salary->fresh()->month, 0, 4)])
            ->with('status', 'Salaire modifié.');
    }

    public function destroy(MonthlySalary $salary): RedirectResponse
    {
        $year = substr($salary->month, 0, 4);
        $salary->delete();

        return redirect()->route('salaries.index', ['year' => $year])->with('status', 'Salaire supprimé.');
    }

    /** @return array{month:string,net_amount_cents:int,note:?string} */
    private function salaryPayload(Request $request, ?MonthlySalary $salary = null): array
    {
        $data = $request->validate([
            'month' => [
                'required',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                Rule::unique('monthly_salaries', 'month')->ignore($salary?->id),
            ],
            'net_amount' => ['required', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if (!DateRange::isMonth($data['month'])) {
            throw ValidationException::withMessages(['month' => 'Mois invalide.']);
        }
        if ($data['month'] > now()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Un salaire reçu ne peut pas être enregistré dans un mois futur.']);
        }

        try {
            $amountCents = Money::parseEuros($data['net_amount']);
        } catch (\InvalidArgumentException $error) {
            throw ValidationException::withMessages(['net_amount' => $error->getMessage()]);
        }
        if ($amountCents <= 0) {
            throw ValidationException::withMessages(['net_amount' => 'Le salaire doit être supérieur à 0 €.']);
        }

        return [
            'month' => $data['month'],
            'net_amount_cents' => $amountCents,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }
}
