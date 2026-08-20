<?php

namespace App\Http\Controllers;

use App\Services\PayrollMath;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\FrenchDate;
use App\Support\Money;
use App\Support\Time;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportController
{
    public function month(string $month, ReportService $reports, SettingsService $settings): StreamedResponse
    {
        abort_unless(DateRange::isMonth($month), 404);
        $report = $reports->month($month);

        return response()->streamDownload(function () use ($report, $settings): void {
            $overtime25ByDate = [];
            $overtime50ByDate = [];
            $overtimeNetByDate = [];
            foreach ($report['weeks'] as $week) {
                $overtime25ByDate += $week['overtime_25_by_date'];
                $overtime50ByDate += $week['overtime_50_by_date'];
                $overtimeNetByDate += $week['overtime_net_by_date'];
            }

            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Date', 'Jour', 'Début', 'Conduite', 'Entrepôt', 'Total', 'Repos', 'Fin', 'Panier (€)', 'HS +25 %', 'HS +50 %', 'Taux net (€)', 'Montant HS net (€)'], ';', '"', '');

            for ($cursor = $report['start']; $cursor <= $report['end']; $cursor = $cursor->modify('+1 day')) {
                $date = $cursor->format('Y-m-d');
                $day = $report['work_days']->get($date);
                $setting = $settings->forDate($date);
                $isRest = $day ? $day->is_rest : (int) $cursor->format('N') === 7;
                $start = $day?->start_time_minutes ?? $setting->default_start_time_minutes;
                $driving = $day?->driving_minutes ?? 0;
                $warehouse = $day?->warehouse_minutes ?? 0;
                $worked = $driving + $warehouse;
                $end = PayrollMath::endTimeMinutes($start, $driving, $warehouse);
                $meal = $day && !$isRest ? PayrollMath::mealAllowanceCents(
                    $end,
                    $worked,
                    $day->meal_allowance_mode,
                    $day->meal_allowance_forced_cents,
                    $setting->meal_allowance_time_minutes,
                    $setting->meal_allowance_cents,
                ) : 0;

                fputcsv($output, [
                    $cursor->format('d/m/Y'),
                    FrenchDate::day($cursor),
                    $isRest ? '' : Time::formatClock($start),
                    $isRest ? '' : Time::formatDuration($driving),
                    $isRest ? '' : Time::formatDuration($warehouse),
                    $isRest ? '' : Time::formatDuration($worked),
                    $isRest ? 'Oui' : 'Non',
                    $isRest ? '' : Time::formatClockWithDayOffset($end),
                    Money::formatInput($meal),
                    Time::formatDuration($overtime25ByDate[$date] ?? 0),
                    Time::formatDuration($overtime50ByDate[$date] ?? 0),
                    Money::formatInput($setting->hourly_net_rate_cents),
                    Money::formatInput($overtimeNetByDate[$date] ?? 0),
                ], ';', '"', '');
            }
            fclose($output);
        }, 'yohan-compta-'.$month.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function year(int $year, ReportService $reports): StreamedResponse
    {
        abort_unless(DateRange::containsYear($year), 404);
        $report = $reports->year($year);

        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Mois', 'Heures travaillées', 'Heures sup effectuées', 'Heures sup restantes',
                'Montant sup net (€)', 'Paniers (€)', 'Montant payé (€)', 'Montant affecté (€)',
                'Montant restant à payer (€)',
            ], ';', '"', '');

            foreach ($report['months'] as $month => $item) {
                fputcsv($output, [
                    FrenchDate::month((int) substr($month, 5, 2)).' '.substr($month, 0, 4),
                    Time::formatDuration($item['worked_minutes']),
                    Time::formatDuration($item['overtime_minutes']),
                    Time::formatDuration($item['remaining_minutes_indicative']),
                    Money::formatInput($item['overtime_net_cents']),
                    Money::formatInput($item['meal_cents']),
                    Money::formatInput($item['paid_received_cents']),
                    Money::formatInput($item['paid_allocated_cents']),
                    Money::formatInput($item['remaining_cents']),
                ], ';', '"', '');
            }
            fclose($output);
        }, 'yohan-compta-'.$year.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
