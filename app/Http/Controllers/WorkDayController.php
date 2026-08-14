<?php

namespace App\Http\Controllers;

use App\Models\WorkDay;
use App\Services\PayrollMath;
use App\Support\Money;
use App\Support\Time;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class WorkDayController
{
    private const DEFAULT_START_MINUTES = 465;

    public function store(Request $request, string $date): JsonResponse
    {
        $this->assertValidDate($date);

        $data = $request->validate([
            'start_time' => ['required', 'regex:/^([01]?\d|2[0-3])(?::[0-5]\d)?$/'],
            'driving' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'warehouse' => ['nullable', 'regex:/^\d{1,3}(?::[0-5]\d)?$/'],
            'is_rest' => ['sometimes', 'boolean'],
            'meal_mode' => ['required', 'in:auto,forced'],
            'meal_amount' => ['nullable', 'string', 'max:30'],
        ], [
            'start_time.regex' => 'Début : format HH ou HH:MM attendu.',
            'driving.regex' => 'Conduite : format HH ou HH:MM attendu.',
            'warehouse.regex' => 'Entrepôt : format HH ou HH:MM attendu.',
        ]);

        $isRest = (bool) ($data['is_rest'] ?? false);
        $isSunday = (int) (new DateTimeImmutable($date))->format('N') === 7;
        if ($isRest) {
            WorkDay::query()->updateOrCreate(['date' => $date], ['is_rest' => true]);

            return response()->json(['ok' => true]);
        }

        $start = Time::parseClock($data['start_time']) ?? self::DEFAULT_START_MINUTES;
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

        $isEmpty = $start === self::DEFAULT_START_MINUTES
            && $driving === 0
            && $warehouse === 0
            && $data['meal_mode'] === 'auto'
            && !$isSunday;

        if ($isEmpty) {
            WorkDay::query()->whereDate('date', $date)->delete();
        } else {
            WorkDay::query()->updateOrCreate(['date' => $date], [
                'start_time_minutes' => $start,
                'driving_minutes' => $driving,
                'warehouse_minutes' => $warehouse,
                'is_rest' => false,
                'meal_allowance_mode' => $data['meal_mode'],
                'meal_allowance_forced_cents' => $forcedCents,
            ]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(string $date): JsonResponse
    {
        $this->assertValidDate($date);
        WorkDay::query()->whereDate('date', $date)->delete();

        return response()->json(['ok' => true]);
    }

    private function assertValidDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int) $parsed->format('Y') < 2000 || (int) $parsed->format('Y') > 2200) {
            abort(404);
        }
    }
}
