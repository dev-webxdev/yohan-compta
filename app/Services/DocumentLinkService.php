<?php

namespace App\Services;

use App\Models\DocumentLink;
use App\Models\LibraryDocument;
use App\Models\MonthlySalary;
use App\Models\OvertimePayment;
use App\Models\WorkDay;
use App\Support\DateRange;
use App\Support\FrenchDate;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

final class DocumentLinkService
{
    /** @return list<array{value:string,label:string}> */
    public function targetOptions(): array
    {
        $options = [];

        foreach (MonthlySalary::query()->orderByDesc('month')->limit(24)->get() as $salary) {
            $options[] = [
                'value' => 'salary:'.$salary->id,
                'label' => 'Salaire '.FrenchDate::month((int) substr($salary->month, 5, 2)).' '.substr($salary->month, 0, 4).' — '.Money::formatCents($salary->net_amount_cents),
            ];
        }

        foreach (OvertimePayment::query()->orderByDesc('payment_date')->orderByDesc('id')->limit(50)->get() as $payment) {
            $options[] = [
                'value' => 'payment:'.$payment->id,
                'label' => 'Paiement heures sup '.$payment->payment_date->format('d/m/Y').' — '.Money::formatCents($payment->amount_cents),
            ];
        }

        $months = [];
        foreach (WorkDay::query()->orderByDesc('date')->limit(366)->pluck('date') as $date) {
            $months[substr((string) $date, 0, 7)] = true;
        }
        foreach (MonthlySalary::query()->pluck('month') as $month) {
            $months[(string) $month] = true;
        }
        $currentMonth = new DateTimeImmutable(now()->format('Y-m-01'));
        for ($offset = 0; $offset < 24; $offset++) {
            $months[$currentMonth->modify('-'.$offset.' months')->format('Y-m')] = true;
        }
        krsort($months);
        foreach (array_slice(array_keys($months), 0, 24) as $month) {
            $options[] = [
                'value' => 'month:'.$month,
                'label' => 'Mois de '.FrenchDate::month((int) substr($month, 5, 2)).' '.substr($month, 0, 4),
            ];
        }

        $weeks = [];
        $days = WorkDay::query()->orderByDesc('date')->limit(180)->get();
        foreach ($days as $day) {
            $week = WeekCalculator::monday($day->date->format('Y-m-d'))->format('Y-m-d');
            $weeks[$week] = true;
        }
        krsort($weeks);
        foreach (array_slice(array_keys($weeks), 0, 36) as $week) {
            $end = (new DateTimeImmutable($week))->modify('+6 days');
            $options[] = [
                'value' => 'week:'.$week,
                'label' => 'Semaine du '.(new DateTimeImmutable($week))->format('d/m').' au '.$end->format('d/m/Y'),
            ];
        }

        foreach ($days as $day) {
            $date = $day->date->format('Y-m-d');
            $state = $day->is_leave ? 'Congé' : ($day->is_rest ? 'Repos' : 'Journée');
            $options[] = ['value' => 'day:'.$date, 'label' => $state.' du '.$day->date->format('d/m/Y')];
        }

        return $options;
    }

    public function attach(LibraryDocument $document, string $token): DocumentLink
    {
        [$type, $key] = $this->parseToken($token, true);

        return DocumentLink::query()->firstOrCreate([
            'document_id' => $document->id,
            'target_type' => $type,
            'target_key' => $key,
        ]);
    }

    public function remove(LibraryDocument $document, DocumentLink $link): void
    {
        if ($link->document_id !== $document->id) {
            throw new RuntimeException('Cette association ne correspond pas au document demandé.');
        }
        $link->delete();
    }

    /** @param list<int|string> $keys @return array<string,int> */
    public function counts(string $type, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        return DocumentLink::query()
            ->where('target_type', $type)
            ->whereIn('target_key', array_map('strval', $keys))
            ->selectRaw('target_key, COUNT(*) as aggregate')
            ->groupBy('target_key')
            ->pluck('aggregate', 'target_key')
            ->map(fn ($count): int => (int) $count)
            ->all();
    }

    public function documentsForToken(string $token): Collection
    {
        [$type, $key] = $this->parseToken($token, false);

        return LibraryDocument::query()
            ->whereHas('links', fn ($query) => $query->where('target_type', $type)->where('target_key', $key))
            ->with(['folder', 'links'])
            ->orderBy('original_name')
            ->get();
    }

    public function label(DocumentLink $link): string
    {
        return match ($link->target_type) {
            'salary' => 'Salaire #'.$link->target_key,
            'payment' => 'Paiement heures sup #'.$link->target_key,
            'month' => 'Mois '.$link->target_key,
            'week' => 'Semaine du '.(new DateTimeImmutable($link->target_key))->format('d/m/Y'),
            'day' => 'Journée du '.(new DateTimeImmutable($link->target_key))->format('d/m/Y'),
            default => $link->target_type.' '.$link->target_key,
        };
    }

    /** @return array{0:string,1:string} */
    private function parseToken(string $token, bool $validate): array
    {
        if (!preg_match('/^(salary|payment|month|week|day):(.+)$/', $token, $match)) {
            throw new RuntimeException('Association de document invalide.');
        }
        $type = $match[1];
        $key = $match[2];

        if ($validate) {
            $valid = match ($type) {
                'salary' => ctype_digit($key) && MonthlySalary::query()->whereKey((int) $key)->exists(),
                'payment' => ctype_digit($key) && OvertimePayment::query()->whereKey((int) $key)->exists(),
                'month' => DateRange::isMonth($key),
                'week' => DateRange::isDate($key) && WeekCalculator::monday($key)->format('Y-m-d') === $key,
                'day' => DateRange::isDate($key) && WorkDay::query()->whereDate('date', $key)->exists(),
            };
            if (!$valid) {
                throw new RuntimeException('La donnée à associer n’existe plus ou est invalide.');
            }
        }

        return [$type, $key];
    }
}
