<?php

namespace Tests\Feature;

use App\Models\MonthlySalary;
use App\Services\SalaryReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalaryTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_salary_page_is_simple_and_exposes_crud_useful_stats_graph_history_and_navigation(): void
    {
        $this->get('/salaires')
            ->assertOk()
            ->assertSee('Enregistrer un salaire')
            ->assertSee('Salaire moyen')
            ->assertSee('Salaire le plus élevé')
            ->assertSee('Salaire le plus faible')
            ->assertSee('Évolution des salaires')
            ->assertSee('Historique des salaires')
            ->assertSee('Salaires</span>', false)
            ->assertDontSee('Suivi des salaires')
            ->assertDontSee('name="note"', false)
            ->assertDontSee('Total des salaires')
            ->assertDontSee('Différence premier / dernier')
            ->assertDontSee('Détail et comparaison mensuelle');

        $this->post('/salaires', [
            'month' => '2026-06',
            'net_amount' => '1850,00',
        ])->assertRedirect('/salaires');

        $this->post('/salaires', [
            'month' => '2026-07',
            'net_amount' => '1920',
        ])->assertRedirect('/salaires');

        self::assertSame(185000, MonthlySalary::query()->where('month', '2026-06')->value('net_amount_cents'));

        $page = $this->get('/salaires')->assertOk();
        $page->assertSee('1 885,00 €')
            ->assertSee('1 920,00 €')
            ->assertSee('1 850,00 €')
            ->assertSee('salary-chart-area', false)
            ->assertSee('salary-chart-line', false)
            ->assertDontSee('3 770,00 €')
            ->assertDontSee('+70,00 €');

        $salary = MonthlySalary::query()->where('month', '2026-06')->firstOrFail();
        $this->patch('/salaires/'.$salary->id, [
            'month' => '2026-06',
            'net_amount' => '1875,50',
        ])->assertRedirect('/salaires');
        self::assertSame(187550, $salary->fresh()->net_amount_cents);

        $july = MonthlySalary::query()->where('month', '2026-07')->firstOrFail();
        $this->delete('/salaires/'.$july->id)->assertRedirect('/salaires');
        self::assertFalse(MonthlySalary::query()->where('month', '2026-07')->exists());
    }

    public function test_salary_month_is_unique_and_future_salary_is_rejected(): void
    {
        MonthlySalary::query()->create(['month' => '2026-08', 'net_amount_cents' => 185000]);

        $this->from('/salaires')->post('/salaires', [
            'month' => '2026-08',
            'net_amount' => '1900',
        ])->assertRedirect('/salaires')->assertSessionHasErrors('month');

        $this->from('/salaires')->post('/salaires', [
            'month' => '2026-12',
            'net_amount' => '1900',
        ])->assertRedirect('/salaires')->assertSessionHasErrors('month');
    }

    public function test_chart_keeps_only_the_twelve_latest_salaries(): void
    {
        for ($index = 0; $index < 13; $index++) {
            $date = now()->startOfMonth()->subMonths(12 - $index);
            MonthlySalary::query()->create([
                'month' => $date->format('Y-m'),
                'net_amount_cents' => 170000 + ($index * 1000),
            ]);
        }

        $report = app(SalaryReportService::class)->build();

        self::assertCount(13, $report['rows']);
        self::assertCount(12, $report['chart']['points']);
        self::assertSame(13, $report['chart']['total_count']);
        self::assertSame(now()->startOfMonth()->subMonths(11)->format('Y-m'), $report['chart']['points'][0]['month']);
    }
}
