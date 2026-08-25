<?php

namespace Tests\Feature;

use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class PlanningAndAnomalyTest extends TestCase
{
    use RefreshDatabase;

    public function test_planning_page_and_update_endpoint_are_removed(): void
    {
        $this->get('/planning')->assertNotFound();
        $this->put('/planning/2026-10-08')->assertNotFound();
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
