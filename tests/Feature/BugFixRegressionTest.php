<?php

namespace Tests\Feature;

use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
        $response->assertSee('Documents</span>', false);
        $response->assertDontSee('Planning</span>', false);
        $response->assertDontSee('Ajouter un jour');
        $response->assertDontSee('aria-label="Apparence"', false);
        $response->assertDontSee('aria-label="Notifications"', false);
        $response->assertDontSee('aria-label="Profil"', false);
    }

    public function test_topbar_title_changes_with_the_current_page(): void
    {
        $this->get('/mois/2026-08')
            ->assertOk()
            ->assertSee('<div class="topbar-title">Tableau de bord</div>', false);

        $this->get('/salaires')
            ->assertOk()
            ->assertSee('<div class="topbar-title">Salaires</div>', false);

        $this->get('/parametres')
            ->assertOk()
            ->assertSee('<div class="topbar-title">Paramètres</div>', false);
    }

    public function test_past_days_are_locked_until_explicitly_unlocked_without_database_state(): void
    {
        Carbon::setTestNow('2026-08-17 12:00:00');

        $content = $this->get('/mois/2026-08')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/data-date="2026-08-16"[^>]*data-locked="1"[^>]*data-unlocked="0"/s', $content);
        self::assertMatchesRegularExpression('/data-date="2026-08-17"[^>]*data-locked="0"/s', $content);
        self::assertStringContainsString('Déverrouiller', $content);

        $payload = [
            'start_time' => '07:45',
            'driving' => '01:00',
            'warehouse' => '',
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ];

        $this->json('PUT', '/jours/2026-08-16', $payload)
            ->assertStatus(423)
            ->assertJsonPath('message', 'Cette journée est verrouillée. Déverrouillez-la avant de la modifier.');

        $this->json('PUT', '/jours/2026-08-16', $payload + ['unlocked' => true])->assertOk();
        $this->json('PUT', '/jours/2026-08-17', $payload)->assertOk();
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
            ->assertSee('Taux horaire net')
            ->assertSee('Heure de début par défaut')
            ->assertDontSee('Taux horaire brut')
            ->assertSee('name="default_start_time" value="07:45"', false)
            ->assertDontSee('<h2>Historique</h2>', false)
            ->assertDontSee('settings-history', false);
    }

    public function test_meal_and_full_day_editing_use_distinct_triggers(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertSee('class="meal-button edit-meal"', false);
        $response->assertSee('class="more-button edit-day"', false);
        $response->assertSee('id="dialog-heading-prefix"', false);
    }

    public function test_week_totals_show_25_percent_overtime_and_net_amount(): void
    {
        $this->get('/mois/2026-08')->assertOk()
            ->assertSee('Heures sup +25 %')
            ->assertDontSee('Heures sup +50 %')
            ->assertSee('Montant heures sup :');
    }

    public function test_dashboard_does_not_duplicate_overtime_payments_panel(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertDontSee('payments-rail', false);
        $response->assertDontSee('Paiements heures sup');
    }

    public function test_overtime_labels_are_explicit_and_consistent_across_pages(): void
    {
        $month = $this->get('/mois/2026-08')->assertOk();
        $payments = $this->get('/paiements')->assertOk();
        $year = $this->get('/annee/2026')->assertOk();

        $month->assertSee('Heures sup effectuées')
            ->assertSee('Montant heures sup')
            ->assertSee('Heures supplémentaires restantes à payer')
            ->assertSee('Heures restantes :')
            ->assertSee('Montant net :');
        $payments->assertSee('Heures supplémentaires payées')
            ->assertSee('Heures supplémentaires restantes à payer')
            ->assertSee('Montant net restant à payer')
            ->assertSee('Montant déjà payé');
        $year->assertSee('Heures sup effectuées')
            ->assertSee('Heures sup restantes')
            ->assertSee('Montant sup à payer')
            ->assertSee('Montant restant à payer');

        foreach ([$month->getContent(), $payments->getContent(), $year->getContent()] as $content) {
            self::assertStringNotContainsString('Reste dû', $content);
            self::assertStringNotContainsString('restant dû', $content);
        }
    }

    public function test_money_formatting_stays_exact_without_float_conversion(): void
    {
        self::assertSame('12,31 €', Money::formatCents(1231));
        self::assertSame('-0,01 €', Money::formatCents(-1));
        self::assertSame('16', Money::formatInput(1600));
        self::assertSame('16,31', Money::formatInput(1631));
    }
}
