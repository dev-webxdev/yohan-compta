<?php

namespace App\Services;

use App\Models\MonthlySalary;

final class AnomalyService
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    /** @return list<array{level:string,label:string,detail:string,url:string}> */
    public function forMonth(string $month): array
    {
        $report = $this->reports->month($month);
        $today = now()->format('Y-m-d');
        $incomplete = 0;
        $unusual = 0;

        foreach ($report['work_days'] as $day) {
            $date = $day->date->format('Y-m-d');
            if ($date >= $today || $day->is_rest || $day->is_leave) {
                continue;
            }
            $worked = $day->driving_minutes + $day->warehouse_minutes;
            if ($worked === 0 && ($day->planned_minutes ?? 0) > 0) {
                $incomplete++;
                continue;
            }
            if ($worked > 12 * 60 || (($day->planned_minutes ?? 0) > 0 && $worked > $day->planned_minutes + 120)) {
                $unusual++;
            }
        }

        $items = [];
        if ($incomplete > 0) {
            $items[] = ['level' => 'danger', 'label' => $incomplete.' journée'.($incomplete > 1 ? 's' : '').' prévue'.($incomplete > 1 ? 's' : '').' sans heures réalisées', 'detail' => 'Prévision dépassée sans saisie réelle.', 'url' => route('month.show', ['month' => $month]).'#days'];
        }
        if ($unusual > 0) {
            $items[] = ['level' => 'warning', 'label' => $unusual.' journée'.($unusual > 1 ? 's' : '').' avec un dépassement inhabituel', 'detail' => 'Plus de 12 h travaillées ou plus de 2 h au-dessus de la prévision.', 'url' => route('month.show', ['month' => $month]).'#days'];
        }
        if ($month <= now()->format('Y-m') && $report['remaining_cents'] > 0) {
            $items[] = ['level' => 'warning', 'label' => 'Heures supplémentaires encore non réglées', 'detail' => 'Un solde reste à payer pour ce mois.', 'url' => route('payments.index')];
        }
        if ($month < now()->format('Y-m') && $report['worked_minutes'] > 0 && !MonthlySalary::query()->where('month', $month)->exists()) {
            $items[] = ['level' => 'warning', 'label' => 'Salaire potentiellement manquant', 'detail' => 'Des heures sont enregistrées mais aucun salaire reçu n’est saisi pour ce mois.', 'url' => route('salaries.index')];
        }

        return $items;
    }
}
