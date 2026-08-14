<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Services\WeekCalculator;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BugFixRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_contains_only_real_sections(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertDontSee('Jours du mois</span>', false);
        $response->assertDontSee('Semaines</span>', false);
        $response->assertDontSee('Reste dû</span>', false);
        $response->assertSee('Heures supplémentaires</span>', false);
        $response->assertSee('Heures sup</span>', false);
        $response->assertSee('Rapports</span>', false);
        $response->assertDontSee('Ajouter un jour');
        $response->assertDontSee('aria-label="Apparence"', false);
        $response->assertDontSee('aria-label="Notifications"', false);
        $response->assertDontSee('aria-label="Profil"', false);
    }

    public function test_secondary_pages_render_with_their_ui_sections(): void
    {
        $this->get('/paiements')
            ->assertOk()
            ->assertSee('payment-balance', false)
            ->assertSee('Ajouter un paiement d’heures supplémentaires')
            ->assertSee('Historique des paiements d’heures supplémentaires');

        $this->get('/annee/2026')
            ->assertOk()
            ->assertSee('report-page', false)
            ->assertSee('Détail mensuel');

        $this->get('/parametres')
            ->assertOk()
            ->assertSee('Taux horaire net');
    }

    public function test_meal_and_full_day_editing_use_distinct_triggers(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertSee('class="meal-button edit-meal"', false);
        $response->assertSee('class="more-button edit-day"', false);
        $response->assertSee('id="dialog-heading-prefix"', false);
        self::assertStringContainsString("openDialog(button.closest('.work-row'), 'meal')", file_get_contents(public_path('app.js')));
    }

    public function test_large_screen_scaling_is_capped_after_1920_pixels(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('@media (min-width:1920px)', $css);
        self::assertStringContainsString('zoom:1.16', $css);
        self::assertStringNotContainsString('zoom:1.32', $css);
        self::assertStringNotContainsString('zoom:1.5', $css);
    }

    public function test_week_totals_stay_next_to_labels_and_only_show_net_overtime_amount(): void
    {
        $this->get('/mois/2026-08')->assertOk()->assertSee('Montant sup :');
        $css = file_get_contents(public_path('app.css'));
        $view = file_get_contents(resource_path('views/month.blade.php'));

        self::assertStringNotContainsString('.week-metrics b{float:right', $css);
        self::assertStringContainsString('.week-metrics b{float:none;margin-left:4px}', $css);
        self::assertStringContainsString("Money::formatCents(\$week['overtime_net_cents']) }} net</b>", $view);
        self::assertStringNotContainsString("\$week['overtime_gross_cents']", $view);
    }

    public function test_future_payment_is_not_shown_as_received_on_dashboard(): void
    {
        OvertimePayment::query()->create([
            'payment_date' => '2026-11-02',
            'amount_cents' => 5000,
            'note' => 'futur',
        ]);

        $this->get('/mois/2026-08')->assertOk()->assertDontSee('02/11/2026');
    }

    public function test_invalid_delete_date_is_rejected(): void
    {
        $this->deleteJson('/jours/2026-99-99')->assertNotFound();
    }

    public function test_month_boundary_can_create_a_one_day_week_segment(): void
    {
        self::assertSame('2026-08-31', WeekCalculator::weekId('2026-08-31'));
        self::assertSame('2026-08-31', WeekCalculator::periodEnd('2026-08-31')->format('Y-m-d'));
        self::assertSame('2026-09-01', WeekCalculator::weekId('2026-09-01'));
    }

    public function test_money_formatting_stays_exact_without_float_conversion(): void
    {
        self::assertSame('12,31 €', Money::formatCents(1231));
        self::assertSame('-0,01 €', Money::formatCents(-1));
        self::assertSame('16', Money::formatInput(1600));
        self::assertSame('16,31', Money::formatInput(1631));
    }
}
