<?php

namespace Tests\Feature;

use App\Models\MonthlySalary;
use App\Models\OvertimePayment;
use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SalaryTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_page_exposes_crud_statistics_chart_and_navigation(): void
    {
        $this->get('/salaires?year=2026')
            ->assertOk()
            ->assertSee('Suivi des salaires')
            ->assertSee('Enregistrer un salaire')
            ->assertSee('Salaire moyen')
            ->assertSee('Évolution du salaire net')
            ->assertSee('Détail et comparaison mensuelle')
            ->assertSee('Salaires</span>', false);

        $this->post('/salaires', [
            'month' => '2026-08',
            'net_amount' => '1850,00',
            'note' => 'Prime été',
        ])->assertRedirect('/salaires?year=2026');

        $this->post('/salaires', [
            'month' => '2026-09',
            'net_amount' => '1920',
            'note' => '',
        ])->assertRedirect('/salaires?year=2026');

        self::assertSame(185000, MonthlySalary::query()->where('month', '2026-08')->value('net_amount_cents'));
        self::assertSame('Prime été', MonthlySalary::query()->where('month', '2026-08')->value('note'));

        $page = $this->get('/salaires?year=2026')->assertOk();
        $page->assertSee('1 885,00 €')
            ->assertSee('1 920,00 €')
            ->assertSee('1 850,00 €')
            ->assertSee('3 770,00 €')
            ->assertSee('+70,00 €')
            ->assertSee('salary-chart-line', false);

        $salary = MonthlySalary::query()->where('month', '2026-08')->firstOrFail();
        $this->patch('/salaires/'.$salary->id, [
            'month' => '2026-08',
            'net_amount' => '1875,50',
            'note' => 'Corrigé',
        ])->assertRedirect('/salaires?year=2026');
        self::assertSame(187550, $salary->fresh()->net_amount_cents);
        self::assertSame('Corrigé', $salary->fresh()->note);

        $september = MonthlySalary::query()->where('month', '2026-09')->firstOrFail();
        $this->delete('/salaires/'.$september->id)->assertRedirect('/salaires?year=2026');
        self::assertFalse(MonthlySalary::query()->where('month', '2026-09')->exists());
    }

    public function test_salary_month_is_unique_and_future_salary_is_rejected(): void
    {
        MonthlySalary::query()->create(['month' => '2026-08', 'net_amount_cents' => 185000]);

        $this->from('/salaires?year=2026')->post('/salaires', [
            'month' => '2026-08',
            'net_amount' => '1900',
        ])->assertRedirect('/salaires?year=2026')->assertSessionHasErrors('month');

        $this->from('/salaires?year=2026')->post('/salaires', [
            'month' => '2026-12',
            'net_amount' => '1900',
        ])->assertRedirect('/salaires?year=2026')->assertSessionHasErrors('month');
    }

    public function test_salary_report_compares_work_overtime_and_received_overtime_payments(): void
    {
        MonthlySalary::query()->create(['month' => '2026-08', 'net_amount_cents' => 185000, 'note' => 'Prime été']);

        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'start_time_minutes' => 465,
                'driving_minutes' => 480,
                'warehouse_minutes' => 0,
                'is_rest' => false,
                'meal_allowance_mode' => 'auto',
            ]);
        }
        OvertimePayment::query()->create([
            'payment_date' => '2026-08-31',
            'amount_cents' => 10000,
            'hours_paid_minutes' => 120,
            'period_reference' => 'Août',
        ]);

        $this->get('/salaires?from=2026-08&to=2026-08')
            ->assertOk()
            ->assertSee('40:00')
            ->assertSee('05:00')
            ->assertSee('100,00 €')
            ->assertSee('02:00')
            ->assertSee('Prime été', false, false);
    }

    public function test_custom_period_filter_limits_salary_rows(): void
    {
        MonthlySalary::query()->create(['month' => '2026-07', 'net_amount_cents' => 180000]);
        MonthlySalary::query()->create(['month' => '2026-08', 'net_amount_cents' => 185000]);
        MonthlySalary::query()->create(['month' => '2026-09', 'net_amount_cents' => 190000]);

        $response = $this->get('/salaires?year=2025&from=2026-08&to=2026-09')->assertOk();
        $response->assertDontSee('1 800,00 €')
            ->assertSee('1 850,00 €')
            ->assertSee('1 900,00 €');

        $this->from('/salaires')->get('/salaires?from=2026-09&to=2026-08')
            ->assertRedirect('/salaires')
            ->assertSessionHasErrors('to');
    }

    public function test_salary_table_is_migrated_and_part_of_current_backup_schema(): void
    {
        self::assertTrue(Schema::hasTable('monthly_salaries'));
        foreach (['month', 'net_amount_cents', 'note'] as $column) {
            self::assertTrue(Schema::hasColumn('monthly_salaries', $column));
        }

        $service = file_get_contents(app_path('Services/DatabaseMaintenanceService.php'));
        self::assertStringContainsString("'monthly_salaries' => ['id', 'month', 'net_amount_cents', 'note', 'created_at', 'updated_at']", $service);
    }
}
