<?php

namespace App\Http\Controllers;

use App\Models\WorkDay;
use App\Services\PayrollMath;
use App\Services\SettingsService;
use App\Support\DateRange;
use App\Support\Money;
use App\Support\Time;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkDayController
{
    public function show(SettingsService $settings, string $date): JsonResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        $setting = $settings->forDate($date);
        $day = WorkDay::query()->whereDate('date', $date)->first();
        $isRest = $day ? $day->is_rest : (int) (new DateTimeImmutable($date))->format('N') === 7;

        return response()->json([
            'date' => $date,
            'start_time' => Time::formatClock($day?->start_time_minutes ?? $setting->default_start_time_minutes),
            'driving' => $day ? Time::formatDuration($day->driving_minutes) : '',
            'warehouse' => $day ? Time::formatDuration($day->warehouse_minutes) : '',
            'is_rest' => $isRest,
            'is_leave' => (bool) ($day?->is_leave ?? false),
            'planned' => $day?->planned_minutes !== null ? Time::formatDuration($day->planned_minutes) : '',
            'meal_mode' => $day?->meal_allowance_mode ?? 'auto',
            'meal_amount' => $day?->meal_allowance_forced_cents !== null ? Money::formatInput($day->meal_allowance_forced_cents) : '',
            'write_version' => (int) ($day?->client_write_version ?? 0),
        ]);
    }

    public function store(Request $request, SettingsService $settings, string $date): JsonResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        if ($date < now()->format('Y-m-d') && !$request->boolean('unlocked')) {
            return response()->json([
                'message' => 'Cette journée est verrouillée. Déverrouillez-la avant de la modifier.',
            ], 423);
        }
        $defaultStart = $settings->forDate($date)->default_start_time_minutes;

        $state = $request->validate([
            'is_rest' => ['sometimes', 'boolean'],
            'unlocked' => ['sometimes', 'boolean'],
            'write_version' => ['nullable', 'integer', 'min:1'],
        ]);
        $isRest = (bool) ($state['is_rest'] ?? false);
        $writeVersion = isset($state['write_version']) ? (int) $state['write_version'] : null;

        if ($isRest) {
            if (!$this->persist($date, ['is_rest' => true, 'is_leave' => false], $defaultStart, $writeVersion)) {
                return $this->staleWriteResponse($date);
            }

            return response()->json(['ok' => true, 'write_version' => $writeVersion]);
        }

        $data = $request->validate([
            'start_time' => ['required', 'regex:/^([01]?\d|2[0-3])(?::[0-5]\d)?$/'],
            'driving' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'warehouse' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'meal_mode' => ['required', 'in:auto,forced'],
            'meal_amount' => ['nullable', 'string', 'max:30'],
        ], [
            'start_time.regex' => 'Début : format HH ou HH:MM attendu.',
            'driving.regex' => 'Conduite : format HH ou HH:MM attendu.',
            'warehouse.regex' => 'Entrepôt : format HH ou HH:MM attendu.',
        ]);

        $isSunday = (int) (new DateTimeImmutable($date))->format('N') === 7;

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

        $existingPlanning = WorkDay::query()->whereDate('date', $date)->first(['planned_minutes', 'is_leave']);
        $hasPlanning = $existingPlanning && ($existingPlanning->planned_minutes !== null || $existingPlanning->is_leave);

        if ($isEmpty && $writeVersion === null && !$hasPlanning) {
            WorkDay::query()->whereDate('date', $date)->delete();

            return response()->json(['ok' => true]);
        }

        $payload = [
            'start_time_minutes' => $start,
            'driving_minutes' => $driving,
            'warehouse_minutes' => $warehouse,
            'is_rest' => false,
            'is_leave' => false,
            'meal_allowance_mode' => $data['meal_mode'],
            'meal_allowance_forced_cents' => $forcedCents,
        ];

        if (!$this->persist($date, $payload, $defaultStart, $writeVersion)) {
            return $this->staleWriteResponse($date);
        }

        return response()->json(['ok' => true, 'write_version' => $writeVersion]);
    }

    public function copyPreviousDay(Request $request, string $date): JsonResponse
    {
        abort_unless(DateRange::isDate($date), 404);
        if ($date < now()->format('Y-m-d') && !$request->boolean('unlocked')) {
            return response()->json([
                'message' => 'Cette journée est verrouillée. Déverrouillez-la avant de la modifier.',
            ], 423);
        }
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

    private function staleWriteResponse(string $date): JsonResponse
    {
        return response()->json([
            'message' => 'Une modification plus récente de cette journée a déjà été enregistrée.',
            'write_version' => (int) (WorkDay::query()->whereDate('date', $date)->value('client_write_version') ?? 0),
        ], 409);
    }

    /** @return array<string,mixed> */
    private function copyPayload(WorkDay $source): array
    {
        return [
            'start_time_minutes' => $source->start_time_minutes,
            'driving_minutes' => $source->driving_minutes,
            'warehouse_minutes' => $source->warehouse_minutes,
            'planned_minutes' => $source->planned_minutes,
            'is_rest' => $source->is_rest,
            'is_leave' => $source->is_leave,
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
}
