<?php

namespace App\Services;

use App\Models\WorkDay;
use App\Support\FrenchDate;
use DateTimeImmutable;

final class PlanningService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly DocumentLinkService $links,
    ) {
    }

    /** @return array<string,mixed> */
    public function build(string $mode, string $date): array
    {
        $anchor = new DateTimeImmutable($date);
        if ($mode === 'week') {
            $start = WeekCalculator::monday($anchor);
            $end = $start->modify('+6 days');
            $previous = $start->modify('-7 days')->format('Y-m-d');
            $next = $start->modify('+7 days')->format('Y-m-d');
            $label = 'Semaine du '.$start->format('d/m').' au '.$end->format('d/m/Y');
            $gridStart = $start;
            $gridEnd = $end;
        } else {
            $start = $anchor->modify('first day of this month');
            $end = $anchor->modify('last day of this month');
            $previous = $start->modify('-1 month')->format('Y-m-d');
            $next = $start->modify('+1 month')->format('Y-m-d');
            $label = FrenchDate::monthYear($start);
            $gridStart = WeekCalculator::monday($start);
            $gridEnd = WeekCalculator::monday($end)->modify('+6 days');
        }

        $workDays = WorkDay::query()
            ->whereBetween('date', [$gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d')])
            ->get()
            ->keyBy(fn (WorkDay $day): string => $day->date->format('Y-m-d'));

        $weekIds = [];
        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $weekIds[WeekCalculator::weekId($cursor)] = true;
        }
        $overtime = [];
        foreach (array_keys($weekIds) as $weekId) {
            foreach ($this->reports->week($weekId)['overtime_by_date'] as $day => $minutes) {
                $overtime[$day] = $minutes;
            }
        }

        $dates = [];
        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor->format('Y-m-d');
        }
        $documentCounts = $this->links->counts('day', $dates);
        $days = [];
        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $day = $workDays->get($key);
            $isLeave = (bool) ($day?->is_leave ?? false);
            $isRest = $day ? $day->is_rest : (int) $cursor->format('N') === 7;
            $worked = $day && !$isRest && !$isLeave ? $day->driving_minutes + $day->warehouse_minutes : 0;
            $planned = $day?->planned_minutes;
            $status = $isLeave ? 'leave' : ($isRest ? 'rest' : ($worked > 0 ? 'worked' : ($planned ? 'planned' : 'empty')));
            $days[] = [
                'date' => $cursor,
                'work_day' => $day,
                'in_period' => $cursor >= $start && $cursor <= $end,
                'status' => $status,
                'worked' => $worked,
                'planned' => $planned,
                'overtime' => (int) ($overtime[$key] ?? 0),
                'document_count' => (int) ($documentCounts[$key] ?? 0),
            ];
        }

        return compact('mode', 'date', 'start', 'end', 'previous', 'next', 'label', 'days');
    }
}
