<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApprovedAuditImprovementsTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_autosave_cannot_overwrite_a_newer_write(): void
    {
        $payload = [
            'start_time' => '07:45',
            'warehouse' => '',
            'is_rest' => false,
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ];

        $this->putJson('/jours/2026-08-17', $payload + [
            'driving' => '09:00',
            'write_version' => 200,
        ])->assertOk();

        $response = $this->putJson('/jours/2026-08-17', $payload + [
            'driving' => '01:00',
            'write_version' => 100,
        ])->assertStatus(409);
        $response->assertJsonPath('write_version', 200);

        $day = WorkDay::query()->whereDate('date', '2026-08-17')->firstOrFail();
        self::assertSame(540, $day->driving_minutes);
        self::assertSame(200, $day->client_write_version);
    }

    public function test_copy_previous_day_replaces_target_without_delete_action(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-17',
            'start_time_minutes' => 480,
            'driving_minutes' => 420,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'forced',
            'meal_allowance_forced_cents' => 1250,
        ]);
        WorkDay::query()->create([
            'date' => '2026-08-18',
            'driving_minutes' => 60,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $this->postJson('/jours/2026-08-18/copier-veille')->assertOk();

        $target = WorkDay::query()->whereDate('date', '2026-08-18')->firstOrFail();
        self::assertSame(480, $target->start_time_minutes);
        self::assertSame(420, $target->driving_minutes);
        self::assertSame(60, $target->warehouse_minutes);
        self::assertSame('forced', $target->meal_allowance_mode);
        self::assertSame(1250, $target->meal_allowance_forced_cents);
        self::assertSame(1, $target->client_write_version);
        $this->deleteJson('/jours/2026-08-18')->assertStatus(405);
    }

    public function test_public_pages_send_security_headers_and_secure_requests_get_hsts(): void
    {
        config(['app.url' => 'https://example.test']);
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('Referrer-Policy', 'same-origin');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        self::assertStringContainsString("frame-ancestors 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));
        self::assertSame('strict', config('session.same_site'));
    }

    public function test_payment_history_can_be_filtered_by_available_month_without_manual_text(): void
    {
        OvertimePayment::query()->create([
            'payment_date' => '2025-08-10',
            'amount_cents' => 1000,
            'period_reference' => 'Ancien paiement',
        ]);
        OvertimePayment::query()->create([
            'payment_date' => '2026-07-10',
            'amount_cents' => 1500,
            'period_reference' => 'Juillet camion',
        ]);
        OvertimePayment::query()->create([
            'payment_date' => '2026-08-10',
            'amount_cents' => 2000,
            'period_reference' => 'Août camion',
        ]);

        $this->get('/paiements?month=2026-08')
            ->assertOk()
            ->assertSee('Août camion')
            ->assertDontSee('Juillet camion')
            ->assertDontSee('Ancien paiement')
            ->assertSee('name="month"', false)
            ->assertSee('Juillet 2026')
            ->assertSee('Août 2026')
            ->assertDontSee('name="q"', false);

        $this->get('/paiements?month=2026-13')->assertNotFound();
    }

    public function test_future_reports_explain_when_the_global_balance_becomes_effective(): void
    {
        $this->get('/mois/2026-12')
            ->assertOk()
            ->assertSee('Mois futur')
            ->assertSee('solde global');

        $this->get('/annee/2027')
            ->assertOk()
            ->assertSee('Année future')
            ->assertSee('solde global');
    }

    public function test_month_ui_exposes_accessibility_and_shortcut_behaviour(): void
    {
        $html = $this->get('/mois/2026-08')->assertOk()->getContent();
        $javascript = file_get_contents(public_path('app.js'));
        $css = file_get_contents(public_path('app.css'));

        self::assertStringContainsString('aria-label="Début du 01/08/2026"', $html);
        self::assertStringContainsString('aria-label="Conduite du 01/08/2026"', $html);
        self::assertStringContainsString('aria-label="Entrepôt du 01/08/2026"', $html);
        self::assertStringContainsString('id="copy-previous-day"', $html);
        self::assertStringNotContainsString('id="delete-day"', $html);
        self::assertStringContainsString('data-write-version="0"', $html);
        self::assertStringContainsString('appMain.inert = open', $javascript);
        self::assertStringContainsString("event.altKey && event.key === 'ArrowDown'", $javascript);
        self::assertStringContainsString("event.key === 'Enter'", $javascript);
        self::assertStringNotContainsString('Recopier la semaine précédente', $html);
        self::assertStringNotContainsString('data-copy-week', $html);
        self::assertStringContainsString('.mobile-menu-backdrop', $css);
    }

    public function test_custom_assets_are_versioned_after_deployments(): void
    {
        $this->get('/mois/2026-08')
            ->assertOk()
            ->assertSee('/app.css?v='.filemtime(public_path('app.css')), false)
            ->assertSee('/app.js?v='.filemtime(public_path('app.js')), false);
        $loginView = file_get_contents(resource_path('views/auth/login.blade.php'));
        self::assertStringContainsString("/auth.css?v={{ filemtime(public_path('auth.css')) }}", $loginView);
    }

}
