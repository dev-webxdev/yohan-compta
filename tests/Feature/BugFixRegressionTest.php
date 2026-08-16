<?php

namespace Tests\Feature;

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
        self::assertStringContainsString("openDialog(button.closest('.work-row'), 'meal')", file_get_contents(public_path('app.js')));
    }

    public function test_day_editor_modal_is_roomy_readable_and_never_scrolls_horizontally(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.day-dialog{width:min(680px,calc(100vw - 32px));', $css);
        self::assertStringContainsString('overflow-y:auto;overflow-x:hidden', $css);
        self::assertStringContainsString('.dialog-head h2{margin:0;font-size:20px;', $css);
        self::assertStringContainsString('.dialog-grid{padding:20px 22px 18px;grid-template-columns:repeat(4,minmax(0,1fr));', $css);
        self::assertStringContainsString('.day-dialog input,.day-dialog textarea{min-width:0;min-height:42px;', $css);
        self::assertStringContainsString('.dialog-copy,.dialog-cancel,.dialog-save{height:40px;', $css);
        self::assertStringContainsString('.dialog-grid{padding:16px;grid-template-columns:1fr 1fr;', $css);
    }

    public function test_work_table_visually_distinguishes_driving_warehouse_total_and_meal_columns(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();
        $css = file_get_contents(public_path('app.css'));

        $response->assertSee('fa-car-side', false)
            ->assertSee('fa-warehouse', false)
            ->assertSee('class="driving-cell"', false)
            ->assertSee('class="warehouse-cell"', false);
        self::assertStringContainsString('.work-table th.driving-head{background:#f2f7ff;', $css);
        self::assertStringContainsString('.work-table th.warehouse-head{background:#fff8ef;', $css);
        self::assertStringContainsString('.driving-cell .time-input{background:#f5f9ff;', $css);
        self::assertStringContainsString('.warehouse-cell .time-input{background:#fff9f2;', $css);
        self::assertStringContainsString('.total-cell strong{display:inline-flex;', $css);
        self::assertStringContainsString('.meal-cell .meal-button{background:#fff8ed;', $css);
        self::assertStringNotContainsString('.meal-cell .meal-button{background:#fff8ed;border-color:#f0dcc2;color:#9a5d18;font-weight:650}', $css);
    }

    public function test_large_screen_layout_is_fluid_without_css_zoom(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('@media (min-width:1600px)', $css);
        self::assertStringContainsString('width:min(calc(100vw - 8px),2200px)', $css);
        self::assertStringContainsString('font-size:clamp(', $css);
        self::assertStringNotContainsString('zoom:', $css);
    }

    public function test_week_totals_show_overtime_tiers_and_majorated_net_amount(): void
    {
        $this->get('/mois/2026-08')->assertOk()->assertSee('Montant heures sup :');
        $css = file_get_contents(public_path('app.css'));
        $view = file_get_contents(resource_path('views/month.blade.php'));

        self::assertStringNotContainsString('.week-metrics b{float:right', $css);
        self::assertStringContainsString('.week-metrics b{float:none;margin:0;font-size:12px;font-weight:650}', $css);
        self::assertStringContainsString('.week-metrics b{font-size:10px}', $css);
        self::assertStringContainsString("Time::formatDuration(\$week['overtime_25_minutes'])", $view);
        self::assertStringContainsString("Time::formatDuration(\$week['overtime_50_minutes'])", $view);
        self::assertStringContainsString("Money::formatCents(\$week['overtime_net_cents']) }} net majoré</b>", $view);
        self::assertStringNotContainsString("\$week['overtime_gross_cents']", $view);
        self::assertStringContainsString('.week-metrics{grid-template-columns:max-content 1fr;', $css);
        self::assertStringContainsString('<span>Total travaillé :</span><b class="blue-value">', $view);
    }

    public function test_week_cards_use_height_instead_of_extra_bottom_padding(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.week-card{min-height:90px;grid-template-columns:1fr}', $css);
        self::assertStringNotContainsString('padding-bottom:11px', $css);
    }

    public function test_icon_text_controls_use_consistent_spacing(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.primary-button,.danger-button{gap:6px}', $css);
        self::assertStringNotContainsString('rail-add-button', $css);
        self::assertStringContainsString('.meal-button,.mobile-more-days{display:inline-flex;align-items:center;justify-content:center;gap:6px}', $css);
    }

    public function test_actions_use_custom_confirmation_dialog_instead_of_browser_confirm(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $settings = file_get_contents(resource_path('views/settings.blade.php'));
        $payments = file_get_contents(resource_path('views/payments.blade.php'));
        $javascript = file_get_contents(public_path('app.js'));
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('id="confirm-dialog"', $layout);
        self::assertStringContainsString('form[data-confirm]', $javascript);
        self::assertStringContainsString('const askConfirmation =', $javascript);
        self::assertStringContainsString("title: 'Recopier la journée précédente ?'", $javascript);
        self::assertStringNotContainsString("method: 'DELETE'", $javascript);
        self::assertStringContainsString('.confirm-dialog.is-danger', $css);
        self::assertStringContainsString('@media(max-width:720px)', $css);
        self::assertStringContainsString('data-confirm-title="Restaurer la base SQLite ?"', $settings);
        self::assertStringContainsString('data-confirm-danger="1"', $payments);
        self::assertStringNotContainsString('confirm(', $settings);
        self::assertStringNotContainsString('confirm(', $payments);
        self::assertStringNotContainsString('confirm(', $javascript);
    }

    public function test_dashboard_summary_panels_keep_inner_spacing(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.weeks-panel,.balance-panel{padding:14px 13px}', $css);
    }

    public function test_payments_history_keeps_space_from_the_panels_above(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.spacer-top{margin-top:16px}', $css);
    }

    public function test_mobile_overtime_summary_keeps_readable_spacing(): void
    {
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('.balance-summary div{grid-template-columns:max-content 1fr;column-gap:8px}', $css);
        self::assertStringContainsString('.balance-summary span{white-space:nowrap}', $css);
        self::assertStringContainsString('.kpi-money{padding-right:12px}', $css);
        self::assertStringContainsString('.kpi-money strong{font-size:14px}', $css);
    }

    public function test_dashboard_does_not_duplicate_overtime_payments_panel(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();
        $controller = file_get_contents(app_path('Http/Controllers/MonthController.php'));

        $response->assertDontSee('payments-rail', false);
        $response->assertDontSee('Paiements heures sup');
        self::assertStringNotContainsString('recentPayments', $controller);
        self::assertStringNotContainsString('OvertimePayment', $controller);
        self::assertStringNotContainsString('payments-rail', file_get_contents(public_path('app-base.css')));
        self::assertStringNotContainsString('recent-payment', file_get_contents(public_path('app-base.css')));
    }

    public function test_table_edits_refresh_dashboard_data_without_reloading_the_page(): void
    {
        $javascript = file_get_contents(public_path('app.js'));

        self::assertStringContainsString('const refreshDashboardSummary = async () =>', $javascript);
        self::assertStringContainsString("['.dashboard-kpis', '#weeks', '#balance', '.below-fold-summary']", $javascript);
        self::assertStringContainsString('void refreshDashboardSummary();', $javascript);
        self::assertStringContainsString('autosaveTimers.set(row, setTimeout', $javascript);
        self::assertStringContainsString('const saveQueues = new WeakMap();', $javascript);
        self::assertStringContainsString('(pending || Promise.resolve())', $javascript);
        self::assertStringContainsString('}, 450));', $javascript);
        self::assertStringNotContainsString('scheduleReload', $javascript);
    }

    public function test_whole_hour_normalization_preserves_complete_time_inputs(): void
    {
        $javascript = file_get_contents(public_path('app.js'));
        $payments = file_get_contents(resource_path('views/payments.blade.php'));

        self::assertStringContainsString("if (/^\\d{1,3}$/.test(normalized)) return `\${normalized}:00`;", $javascript);
        self::assertStringContainsString("return `\${hours}:\${minutes.padStart(2, '0')}`;", $javascript);
        self::assertStringNotContainsString('normalized.padStart', $javascript);
        self::assertStringContainsString('data-time-normalize', $payments);
        self::assertStringContainsString("qa('[data-time-normalize]')", $javascript);
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

        self::assertStringContainsString('Heures sup payées :', file_get_contents(resource_path('views/payments.blade.php')));

        foreach ([$month->getContent(), $payments->getContent(), $year->getContent()] as $content) {
            self::assertStringNotContainsString('Reste dû', $content);
            self::assertStringNotContainsString('restant dû', $content);
        }
    }

    public function test_work_day_delete_route_no_longer_exists(): void
    {
        $this->deleteJson('/jours/2026-08-03')->assertStatus(405);
    }

    public function test_month_boundary_splits_week_without_cross_month_hours(): void
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
