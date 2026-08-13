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
    public function store(Request $request, string $date): JsonResponse
    {
        $this->assertValidDate($date);

        $data = $request->validate([
            'driving' => ['nullable', 'regex:/^\d{1,3}:[0-5]\d$/'],
            'warehouse' => ['nullable', 'regex:/^\d{1,3}:[0-5]\d$/'],
            'end_time' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'meal_mode' => ['required', 'in:auto,forced'],
            'meal_amount' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [
            'driving.regex' => 'Conduite : format HH:MM attendu.',
            'warehouse.regex' => 'Entrepôt : format HH:MM attendu.',
            'end_time.regex' => 'Heure de fin : format HH:MM attendu.',
        ]);

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

        $isEmpty = $driving === 0
            && $warehouse === 0
            && empty($data['end_time'])
            && $data['meal_mode'] === 'auto'
            && trim((string) ($data['note'] ?? '')) === '';

        if ($isEmpty) {
            WorkDay::query()->whereDate('date', $date)->delete();
        } else {
            WorkDay::query()->updateOrCreate(['date' => $date], [
                'driving_minutes' => $driving,
                'warehouse_minutes' => $warehouse,
                'end_time_minutes' => Time::parseClock($data['end_time'] ?? ''),
                'meal_allowance_mode' => $data['meal_mode'],
                'meal_allowance_forced_cents' => $forcedCents,
                'note' => trim((string) ($data['note'] ?? '')) ?: null,
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
