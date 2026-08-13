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

    public function test_dashboard_links_keep_the_selected_month(): void
    {
        $response = $this->get('/mois/2026-08')->assertOk();

        $response->assertSee('/mois/2026-08#days', false);
        $response->assertSee('/mois/2026-08#weeks', false);
        $response->assertSee('/mois/2026-08#balance', false);
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
    }
}
