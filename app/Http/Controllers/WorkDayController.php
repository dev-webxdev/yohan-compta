<?php

namespace App\Http\Controllers;

use App\Models\WorkDay;
use App\Services\PayrollMath;
use App\Services\SettingsService;
use App\Services\WeekCalculator;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkDayController
{
    public function store(Request $request, SettingsService $settings, string $date): JsonResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        $defaultStart = $settings->forDate($date)->default_start_time_minutes;

        $data = $request->validate([
            'start_time' => ['required', 'regex:/^([01]?\d|2[0-3])(?::[0-5]\d)?$/'],
            'driving' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'warehouse' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'is_rest' => ['sometimes', 'boolean'],
            'meal_mode' => ['required', 'in:auto,forced'],
            'meal_amount' => ['nullable', 'string', 'max:30'],
            'write_version' => ['nullable', 'integer', 'min:1'],
        ], [
            'start_time.regex' => 'Début : format HH ou HH:MM attendu.',
            'driving.regex' => 'Conduite : format HH ou HH:MM attendu.',
            'warehouse.regex' => 'Entrepôt : format HH ou HH:MM attendu.',
        ]);

        $isRest = (bool) ($data['is_rest'] ?? false);
        $isSunday = (int) (new DateTimeImmutable($date))->format('N') === 7;
        $writeVersion = isset($data['write_version']) ? (int) $data['write_version'] : null;

        if ($isRest) {
            if (!$this->persist($date, ['is_rest' => true], $defaultStart, $writeVersion)) {
                return $this->staleWriteResponse();
            }

            return response()->json(['ok' => true, 'write_version' => $writeVersion]);
        }

        $start = Time::parseClock($data['start_time']) ?? $defaultStart;
        $driving = Time::parseDuration($data['driving'] ?? '');
        $warehouse = Time::parseDuration($data['warehouse'] ?? '');

        try {
            PayrollMath::totalWorked($driving, $warehouse);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['driving' => $e->getMessage()]);
        }

        $forcedCents = null;
        if ($data['meal_mode'] === 'forced') {
            try {
                $forcedCents = Money::parseEuros($data['meal_amount'] ?? '0');
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['meal_amount' => $e->getMessage()]);
            }
        }

        $isEmpty = $start === $defaultStart
            && $driving === 0
            && $warehouse === 0
            && $data['meal_mode'] === 'auto'
            && !$isSunday;

        if ($isEmpty && $writeVersion === null) {
            WorkDay::query()->whereDate('date', $date)->delete();

            return response()->json(['ok' => true]);
        }

        $payload = [
            'start_time_minutes' => $start,
            'driving_minutes' => $driving,
            'warehouse_minutes' => $warehouse,
            'is_rest' => false,
            'meal_allowance_mode' => $data['meal_mode'],
            'meal_allowance_forced_cents' => $forcedCents,
        ];

        if (!$this->persist($date, $payload, $defaultStart, $writeVersion)) {
            return $this->staleWriteResponse();
        }

        return response()->json(['ok' => true, 'write_version' => $writeVersion]);
    }

    public function copyPreviousDay(string $date): JsonResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        $sourceDate = (new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        if (!DateRange::isDate($sourceDate)) {
            return response()->json(['message' => 'Aucune journée précédente disponible.'], 422);
        }

        $source = WorkDay::query()->whereDate('date', $sourceDate)->first();
        if (!$source) {
            return response()->json(['message' => 'La journée précédente ne contient aucune saisie à recopier.'], 422);
        }

        $this->copyToDate($date, $source);

        return response()->json([
            'ok' => true,
            'message' => 'Journée du '.(new DateTimeImmutable($sourceDate))->format('d/m/Y').' recopiée.',
        ]);
    }

    public function previewPreviousWeek(string $week): JsonResponse
    {
        $range = $this->weekCopyRange($week);
        $items = [];

        for ($target = $range['start']; $target <= $range['end']; $target = $target->modify('+1 day')) {
            $source = $target->modify('-7 days');
            $sourceDay = WorkDay::query()->whereDate('date', $source->format('Y-m-d'))->first();
            $items[] = [
                'source' => $source->format('d/m/Y'),
                'target' => $target->format('d/m/Y'),
                'has_source' => $sourceDay !== null,
            ];
        }

        return response()->json([
            'count' => count(array_filter($items, static fn (array $item): bool => $item['has_source'])),
            'items' => $items,
        ]);
    }

    public function copyPreviousWeek(string $week): JsonResponse
    {
        $range = $this->weekCopyRange($week);
        $copied = 0;

        for ($target = $range['start']; $target <= $range['end']; $target = $target->modify('+1 day')) {
            $sourceDate = $target->modify('-7 days')->format('Y-m-d');
            $source = WorkDay::query()->whereDate('date', $sourceDate)->first();
            if (!$source) {
                continue;
            }

            $this->copyToDate($target->format('Y-m-d'), $source);
            $copied++;
        }

        if ($copied === 0) {
            return response()->json(['message' => 'La semaine précédente ne contient aucune saisie à recopier.'], 422);
        }

        return response()->json(['ok' => true, 'copied' => $copied]);
    }

    /** @param array<string,mixed> $payload */
    private function persist(string $date, array $payload, int $defaultStart, ?int $writeVersion): bool
    {
        if ($writeVersion === null) {
            WorkDay::query()->updateOrCreate(['date' => $date], $payload);

            return true;
        }

        WorkDay::query()->insertOrIgnore([
            'date' => $date,
            'start_time_minutes' => $defaultStart,
            'client_write_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WorkDay::query()
            ->whereDate('date', $date)
            ->where('client_write_version', '<', $writeVersion)
            ->update($payload + [
                'client_write_version' => $writeVersion,
                'updated_at' => now(),
            ]) === 1;
    }

    private function staleWriteResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Une modification plus récente de cette journée a déjà été enregistrée. Rechargez la page avant de continuer.',
        ], 409);
    }

    /** @return array<string,mixed> */
    private function copyPayload(WorkDay $source): array
    {
        return [
            'start_time_minutes' => $source->start_time_minutes,
            'driving_minutes' => $source->driving_minutes,
            'warehouse_minutes' => $source->warehouse_minutes,
            'is_rest' => $source->is_rest,
            'meal_allowance_mode' => $source->meal_allowance_mode,
            'meal_allowance_forced_cents' => $source->meal_allowance_forced_cents,
        ];
    }

    private function copyToDate(string $date, WorkDay $source): void
    {
        $currentVersion = (int) (WorkDay::query()
            ->whereDate('date', $date)
            ->value('client_write_version') ?? 0);

        WorkDay::query()->updateOrCreate(
            ['date' => $date],
            $this->copyPayload($source) + [
                'client_write_version' => $currentVersion + 1,
            ],
        );
    }

    /** @return array{start:DateTimeImmutable,end:DateTimeImmutable} */
    private function weekCopyRange(string $week): array
    {
        abort_unless(DateRange::isDate($week) && WeekCalculator::weekId($week) === $week, 404);

        $start = new DateTimeImmutable($week);

        return ['start' => $start, 'end' => WeekCalculator::periodEnd($start)];
    }
}
