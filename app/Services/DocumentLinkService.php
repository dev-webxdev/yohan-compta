<?php

namespace App\Services;

use App\Models\DocumentLink;
use App\Models\LibraryDocument;
use App\Models\MonthlySalary;
use App\Models\WorkDay;
use App\Support\DateRange;
use App\Support\FrenchDate;
use App\Support\Money;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use RuntimeException;

final class DocumentLinkService
{
    /** @return array{salary:list<array{value:string,label:string}>,month:list<array{value:string,label:string}>} */
    public function targetOptions(): array
    {
        $options = ['salary' => [], 'month' => []];

        foreach (MonthlySalary::query()->where('month', '<=', now()->format('Y-m'))->orderByDesc('month')->get() as $salary) {
            $options['salary'][] = [
                'value' => 'salary:'.$salary->id,
                'label' => 'Salaire '.FrenchDate::month((int) substr($salary->month, 5, 2)).' '.substr($salary->month, 0, 4).' — '.Money::formatCents($salary->net_amount_cents),
            ];
        }

        $months = [];
        foreach (WorkDay::query()
            ->whereDate('date', '<=', now()->format('Y-m-d'))
            ->where(fn ($query) => $query->where('driving_minutes', '>', 0)->orWhere('warehouse_minutes', '>', 0))
            ->orderByDesc('date')
            ->pluck('date') as $date) {
            $month = substr((string) $date, 0, 7);
            $months[$month] = true;
        }
        foreach (array_keys($months) as $month) {
            $options['month'][] = [
                'value' => 'month:'.$month,
                'label' => FrenchDate::month((int) substr($month, 5, 2)).' '.substr($month, 0, 4),
            ];
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
            'salary' => $this->salaryLabel($link->target_key),
            'payment' => 'Paiement heures sup #'.$link->target_key,
            'month' => 'Heures · '.FrenchDate::month((int) substr($link->target_key, 5, 2)).' '.substr($link->target_key, 0, 4),
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
            if (!in_array($type, ['salary', 'month'], true)) {
                throw new RuntimeException('Un document peut uniquement être associé à un salaire ou aux heures d’un mois.');
            }
            $valid = match ($type) {
                'salary' => ctype_digit($key) && MonthlySalary::query()->whereKey((int) $key)->exists(),
                'month' => $this->monthHasRecordedHours($key),
            };
            if (!$valid) {
                throw new RuntimeException('La donnée à associer n’existe plus ou est invalide.');
            }
        }

        return [$type, $key];
    }

    private function monthHasRecordedHours(string $month): bool
    {
        if (!DateRange::isMonth($month) || $month > now()->format('Y-m')) {
            return false;
        }

        return WorkDay::query()
            ->whereBetween('date', [$month.'-01', $month.'-31'])
            ->where(fn ($query) => $query->where('driving_minutes', '>', 0)->orWhere('warehouse_minutes', '>', 0))
            ->exists();
    }

    private function salaryLabel(string $key): string
    {
        if (!ctype_digit($key)) {
            return 'Salaire #'.$key;
        }

        $salary = MonthlySalary::query()->find((int) $key);
        if (!$salary) {
            return 'Salaire supprimé';
        }

        return 'Salaire · '.FrenchDate::month((int) substr($salary->month, 5, 2)).' '.substr($salary->month, 0, 4);
    }
}
