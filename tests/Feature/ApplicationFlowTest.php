<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Models\SettingPeriod;
use App\Models\WorkDay;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApplicationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_month_displays_exact_real_days_only(): void
    {
        $response = $this->get('/mois/2026-04');
        $response->assertOk();
        $response->assertSee('30/04/2026');
        $response->assertDontSee('01/05/2026');
        self::assertSame(30, substr_count($response->getContent(), 'class="work-row"'));

        self::assertSame(28, substr_count($this->get('/mois/2026-02')->getContent(), 'class="work-row"'));
        self::assertSame(29, substr_count($this->get('/mois/2028-02')->getContent(), 'class="work-row"'));
        self::assertSame(31, substr_count($this->get('/mois/2026-08')->getContent(), 'class="work-row"'));
    }

    public function test_month_boundary_resets_weekly_overtime_counter(): void
    {
        foreach ([
            '2026-07-27' => '07:00', '2026-07-28' => '07:00', '2026-07-29' => '07:00', '2026-07-30' => '07:00',
            '2026-07-31' => '05:00', '2026-08-01' => '03:00', '2026-08-02' => '04:00',
        ] as $date => $duration) {
            $this->putJson('/jours/'.$date, ['driving' => $duration, 'warehouse' => '', 'end_time' => '', 'meal_mode' => 'auto', 'meal_amount' => '', 'note' => ''])->assertOk();
        }

        $reports = app(ReportService::class);
        self::assertSame(0, $reports->week('2026-07-27')['overtime_minutes']);
        self::assertSame(0, $reports->week('2026-08-01')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-07')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-08')['overtime_minutes']);

        $this->putJson('/jours/2026-07-31', ['driving' => '09:00', 'warehouse' => '', 'end_time' => '', 'meal_mode' => 'auto', 'meal_amount' => '', 'note' => ''])->assertOk();
        self::assertSame(120, $reports->week('2026-07-27')['overtime_minutes']);
        self::assertSame(0, $reports->week('2026-08-01')['overtime_minutes']);
        self::assertSame(120, $reports->month('2026-07')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-08')['overtime_minutes']);
    }

    public function test_meal_validation_and_forced_override(): void
    {
        $this->putJson('/jours/2026-08-03', ['driving' => '05:15', 'warehouse' => '01:00', 'end_time' => '14:15', 'meal_mode' => 'auto', 'meal_amount' => '', 'note' => ''])->assertOk();
        self::assertSame(1600, app(ReportService::class)->month('2026-08')['meal_cents']);

        $this->putJson('/jours/2026-08-03', ['driving' => '05:15', 'warehouse' => '01:00', 'end_time' => '13:00', 'meal_mode' => 'forced', 'meal_amount' => '8,50', 'note' => 'exception'])->assertOk();
        self::assertSame(850, app(ReportService::class)->month('2026-08')['meal_cents']);

        $this->putJson('/jours/2026-08-04', ['driving' => '14:75', 'warehouse' => '', 'end_time' => '', 'meal_mode' => 'auto'])->assertUnprocessable();
        $this->putJson('/jours/2026-08-04', ['driving' => '20:00', 'warehouse' => '05:00', 'end_time' => '', 'meal_mode' => 'auto'])->assertUnprocessable();
    }

    public function test_payment_fifo_keeps_history_and_monthly_global_balances(): void
    {
        foreach (['2026-07-27','2026-07-28','2026-07-29','2026-07-30','2026-07-31','2026-08-01','2026-08-02'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 360, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }
        foreach (['2026-08-03','2026-08-04','2026-08-05','2026-08-06','2026-08-07','2026-08-08','2026-08-09'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 360, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }

        $beforeJuly = app(ReportService::class)->month('2026-07')['overtime_cents'];
        $beforeAugust = app(ReportService::class)->month('2026-08')['overtime_cents'];
        self::assertGreaterThan(0, $beforeJuly + $beforeAugust);

        $this->post('/paiements', ['payment_date' => '2026-10-15', 'amount' => '50,00', 'hours_paid' => '', 'note' => 'partiel'])->assertRedirect('/paiements');
        self::assertSame(1, OvertimePayment::query()->count());
        $payment = OvertimePayment::query()->firstOrFail();
        self::assertSame(5000, $payment->amount_cents);
        self::assertNotEmpty(app(ReportService::class)->paymentAllocations()[$payment->id] ?? []);

        self::assertSame($beforeJuly, app(ReportService::class)->month('2026-07')['overtime_cents']);
        self::assertSame($beforeAugust, app(ReportService::class)->month('2026-08')['overtime_cents']);
        self::assertSame(5000, app(ReportService::class)->balance()['paid']);

        WorkDay::query()->where('date', '2026-07-31')->delete();
        $after = app(ReportService::class)->balance();
        self::assertSame(5000, $after['paid']);
        self::assertSame(max(0, $after['generated'] - 5000), $after['remaining']);
    }

    public function test_settings_are_effective_dated_without_rewriting_history(): void
    {
        WorkDay::query()->create(['date' => '2026-08-01', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        $old = app(ReportService::class)->month('2026-08')['work_pay_cents'];
        self::assertSame(1231, $old);

        $this->post('/parametres', ['effective_from' => '2026-08-15', 'hourly_rate' => '15,00', 'weekly_threshold' => '35:00', 'meal_allowance' => '16,00', 'meal_allowance_time' => '14:15'])->assertRedirect('/parametres');
        WorkDay::query()->create(['date' => '2026-08-16', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        self::assertSame(2731, app(ReportService::class)->month('2026-08')['work_pay_cents']);
        self::assertSame(2, SettingPeriod::query()->count());
    }

    public function test_delete_day_restores_empty_calendar_day(): void
    {
        WorkDay::query()->create(['date' => '2026-08-03', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        $this->deleteJson('/jours/2026-08-03')->assertOk();
        self::assertSame(0, WorkDay::query()->count());
        $this->get('/mois/2026-08')->assertSee('03/08/2026')->assertSee('00:00');
    }
}
