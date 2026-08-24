<?php

namespace App\Http\Controllers;

use App\Models\DocumentLink;
use App\Models\MonthlySalary;
use App\Services\DocumentLinkService;
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
    public function index(Request $request, SalaryReportService $reports, DocumentLinkService $links): View
    {
        $filters = $request->validate([
            'edit' => ['nullable', 'integer', 'min:1'],
        ]);
        $editId = isset($filters['edit']) ? (int) $filters['edit'] : 0;

        $report = $reports->build();
        return view('salaries', [
            'report' => $report,
            'editingSalary' => $editId > 0 ? MonthlySalary::query()->find($editId) : null,
            'documentCounts' => $links->counts('salary', $report['rows']->pluck('id')->map(fn ($id): int => (int) $id)->all()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        MonthlySalary::query()->create($this->salaryPayload($request));

        return redirect()->route('salaries.index')->with('status', 'Salaire enregistré.');
    }

    public function update(Request $request, MonthlySalary $salary): RedirectResponse
    {
        $salary->update($this->salaryPayload($request, $salary));

        return redirect()->route('salaries.index')->with('status', 'Salaire modifié.');
    }

    public function destroy(MonthlySalary $salary): RedirectResponse
    {
        DocumentLink::query()->where('target_type', 'salary')->where('target_key', (string) $salary->id)->delete();
        $salary->delete();

        return redirect()->route('salaries.index')->with('status', 'Salaire supprimé.');
    }

    /** @return array{month:string,net_amount_cents:int} */
    private function salaryPayload(Request $request, ?MonthlySalary $salary = null): array
    {
        $data = $request->validate([
            'month' => [
                'required',
                'regex:/^\d{4}-(0[1-9]|1[0-2])$/',
                Rule::unique('monthly_salaries', 'month')->ignore($salary?->id),
            ],
            'net_amount' => ['required', 'string', 'max:30'],
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
        ];
    }
}
