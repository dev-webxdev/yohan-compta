<?php

namespace Tests\Feature;

use App\Models\WorkDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
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

        $this->postJson('/jours/2026-08-18/copier-veille', ['unlocked' => true])->assertOk();

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

    public function test_trusted_reverse_proxy_keeps_generated_urls_on_https(): void
    {
        TrustProxies::at('*');

        try {
            $this->withSession(['auth.authenticated' => false])
                ->withHeaders([
                    'X-Forwarded-Host' => 'compta.example.test',
                    'X-Forwarded-Port' => '443',
                    'X-Forwarded-Proto' => 'https',
                ])->get('/connexion')
                ->assertOk()
                ->assertSee('action="https://compta.example.test/connexion"', false)
                ->assertHeader('Strict-Transport-Security');
        } finally {
            TrustProxies::flushState();
        }
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
