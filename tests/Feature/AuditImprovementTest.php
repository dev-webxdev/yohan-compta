<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Models\WorkDay;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuditImprovementTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_keep_submitted_values_and_show_field_error(): void
    {
        $this->from('/parametres')->post('/parametres', [
            'effective_from' => '2026-08-14',
            'default_start_time' => '07:45',
            'hourly_gross_rate' => 'abc',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres')->assertSessionHasErrors('hourly_gross_rate');

        $this->get('/parametres')
            ->assertOk()
            ->assertSee('value="abc"', false)
            ->assertSee('field-error', false)
            ->assertSee('Montant invalide.');
    }

    public function test_existing_payment_can_be_edited_without_recreating_it(): void
    {
        $payment = OvertimePayment::query()->create([
            'payment_date' => '2026-08-10',
            'amount_cents' => 1000,
            'period_reference' => 'Ancienne référence',
            'note' => 'Avant correction',
        ]);

        $this->get('/paiements?edit='.$payment->id)
            ->assertOk()
            ->assertSee('Modifier le paiement')
            ->assertSee(route('payments.update', $payment), false)
            ->assertSee('value="10"', false)
            ->assertSee('Ancienne référence');

        $this->patch(route('payments.update', $payment), [
            'payment_date' => '2026-08-14',
            'amount' => '12,50',
            'hours_paid' => '',
            'period_reference' => 'Août 2026',
            'note' => 'Montant corrigé',
        ])->assertRedirect('/paiements');

        $payment->refresh();
        self::assertSame('2026-08-14', $payment->payment_date->format('Y-m-d'));
        self::assertSame(1250, $payment->amount_cents);
        self::assertNull($payment->hours_paid_minutes);
        self::assertSame('Août 2026', $payment->period_reference);
        self::assertSame('Montant corrigé', $payment->note);
        self::assertSame(1, OvertimePayment::query()->count());
    }

    public function test_edit_can_add_explicit_hours_to_an_existing_indicative_payment(): void
    {
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => 480,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $debt = app(ReportService::class)->month('2026-08')['overtime_net_cents'];
        $payment = OvertimePayment::query()->create([
            'payment_date' => '2026-08-14',
            'amount_cents' => $debt,
        ]);
        self::assertSame(0, app(ReportService::class)->balance()['remaining_minutes_indicative']);

        $this->patch(route('payments.update', $payment), [
            'payment_date' => '2026-08-14',
            'amount' => (string) ($debt / 100),
            'hours_paid' => '05:00',
            'period_reference' => '',
            'note' => '',
        ])->assertRedirect('/paiements')->assertSessionHasNoErrors();

        self::assertSame(300, $payment->fresh()->hours_paid_minutes);
    }

    public function test_month_and_year_can_be_exported_as_csv(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-03',
            'start_time_minutes' => 465,
            'driving_minutes' => 360,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'auto',
        ]);

        $month = $this->get(route('month.export', ['month' => '2026-08']))
            ->assertOk()
            ->assertDownload('yohan-compta-2026-08.csv');
        $monthCsv = $month->streamedContent();
        self::assertStringContainsString('Date;Jour;', $monthCsv);
        self::assertStringContainsString('03/08/2026;Lundi;07:45;06:00;01:00;07:00;Non;14:45;16', $monthCsv);

        $year = $this->get(route('year.export', ['year' => 2026]))
            ->assertOk()
            ->assertDownload('yohan-compta-2026.csv');
        $yearCsv = $year->streamedContent();
        self::assertStringContainsString('Mois;"Heures travaillées";', $yearCsv);
        self::assertStringContainsString('"Août 2026";', $yearCsv);
    }

    public function test_dashboard_balance_lists_only_months_still_due(): void
    {
        foreach (['2026-07-06', '2026-07-07', '2026-07-08', '2026-07-09', '2026-07-10'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }

        $julyDebt = app(ReportService::class)->month('2026-07')['overtime_net_cents'];
        self::assertGreaterThan(0, $julyDebt);
        OvertimePayment::query()->create(['payment_date' => '2026-08-14', 'amount_cents' => $julyDebt]);

        $response = $this->get('/mois/2026-08')->assertOk();
        $response->assertDontSee('Juillet :</span>', false);
        $response->assertSee('Août :</span>', false);
    }

    public function test_audit_ui_improvements_are_present(): void
    {
        $css = file_get_contents(public_path('app.css'));
        $javascript = file_get_contents(public_path('app.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $settings = file_get_contents(resource_path('views/settings.blade.php'));

        self::assertStringContainsString('grid-template-columns:repeat(2,minmax(0,1fr))', $css);
        self::assertStringContainsString('.report-table thead th{position:sticky', $css);
        self::assertStringContainsString('a:focus-visible,button:focus-visible', $css);
        self::assertStringContainsString('.mobile-nav a{font-size:10px}', $css);
        self::assertStringContainsString('const setRowSaveState =', $javascript);
        self::assertStringContainsString('const showSaveError =', $javascript);
        self::assertStringContainsString("q('#confirm-dialog-cancel')?.focus();", $javascript);
        self::assertStringContainsString('/vendor/fontawesome/css/fontawesome.min.css', $layout);
        self::assertStringNotContainsString('cdnjs.cloudflare.com/ajax/libs/font-awesome', $layout);
        self::assertFileExists(public_path('vendor/fontawesome/webfonts/fa-solid-900.woff2'));
        self::assertStringNotContainsString('Je confirme la restauration de la base sélectionnée.', $settings);
        self::assertStringNotContainsString('Je confirme la suppression des données de ce mois.', $settings);
        self::assertStringContainsString('Je confirme la suppression complète des données.', $settings);
    }
}
