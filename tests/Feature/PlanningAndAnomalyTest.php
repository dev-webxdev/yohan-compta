<?php

namespace Tests\Feature;

use App\Models\WorkDay;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PlanningAndAnomalyTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_reuses_actual_hours_and_supports_month_and_week_views(): void
    {
        WorkDay::query()->create([
            'date' => '2026-10-05',
            'start_time_minutes' => 465,
            'driving_minutes' => 420,
            'warehouse_minutes' => 60,
            'planned_minutes' => 420,
            'meal_allowance_mode' => 'auto',
        ]);

        $this->get('/planning?view=month&date=2026-10-05')
            ->assertOk()
            ->assertSee('Planning')
            ->assertSee('Mois')
            ->assertSee('Semaine')
            ->assertSee('07:00')
            ->assertSee('08:00')
            ->assertSee('Travaillé');

        $this->get('/planning?view=week&date=2026-10-05')
            ->assertOk()
            ->assertSee('Semaine du 05/10 au 11/10/2026')
            ->assertSee('08:00');
    }

    public function test_planning_can_store_forecast_and_leave_without_counting_leave_as_work(): void
    {
        WorkDay::query()->create([
            'date' => '2026-10-08',
            'start_time_minutes' => 465,
            'driving_minutes' => 360,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'auto',
        ]);

        $this->put('/planning/2026-10-08', [
            'planned' => '07:30',
            'is_leave' => '1',
            'return_view' => 'month',
            'return_date' => '2026-10-01',
        ])->assertRedirect('/planning?view=month&date=2026-10-01');

        $day = WorkDay::query()->whereDate('date', '2026-10-08')->firstOrFail();
        self::assertSame(450, $day->planned_minutes);
        self::assertTrue($day->is_leave);
        self::assertSame(0, $day->driving_minutes);
        self::assertSame(0, $day->warehouse_minutes);
        self::assertSame(0, app(ReportService::class)->month('2026-10')['worked_minutes']);

        $this->get('/mois/2026-10')->assertOk()->assertSee('Congé');
        $this->get('/planning?view=month&date=2026-10-01')->assertOk()->assertSee('Congé');
    }

    public function test_dashboard_only_surfaces_relevant_detectable_anomalies(): void
    {
        Carbon::setTestNow('2026-11-01 12:00:00');
        WorkDay::query()->create([
            'date' => '2026-10-05',
            'planned_minutes' => 420,
            'driving_minutes' => 0,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        WorkDay::query()->create([
            'date' => '2026-10-06',
            'planned_minutes' => 420,
            'driving_minutes' => 600,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        WorkDay::query()->create([
            'date' => '2026-10-07',
            'planned_minutes' => 420,
            'is_leave' => true,
            'driving_minutes' => 0,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $this->get('/mois/2026-10')
            ->assertOk()
            ->assertSee('À vérifier')
            ->assertSee('journée prévue sans heures réalisées')
            ->assertSee('journée avec un dépassement inhabituel')
            ->assertSee('Salaire potentiellement manquant')
            ->assertDontSee('heure de fin manquante');
    }
}
