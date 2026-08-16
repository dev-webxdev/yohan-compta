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
        self::assertSame(30, substr_count($response->getContent(), '<tr class="work-row'));

        self::assertSame(28, substr_count($this->get('/mois/2026-02')->getContent(), '<tr class="work-row'));
        self::assertSame(29, substr_count($this->get('/mois/2028-02')->getContent(), '<tr class="work-row'));
        self::assertSame(31, substr_count($this->get('/mois/2026-08')->getContent(), '<tr class="work-row'));
    }

    public function test_month_boundary_isolates_weekly_overtime(): void
    {
        foreach ([
            '2026-07-27' => '07:00', '2026-07-28' => '07:00', '2026-07-29' => '07:00', '2026-07-30' => '07:00',
            '2026-07-31' => '05:00', '2026-08-01' => '03:00', '2026-08-02' => '04:00',
        ] as $date => $duration) {
            $this->putJson('/jours/'.$date, ['start_time' => '07:45', 'driving' => $duration, 'warehouse' => '', 'meal_mode' => 'auto', 'meal_amount' => ''])->assertOk();
        }

        $reports = app(ReportService::class);
        self::assertSame(0, $reports->week('2026-07-27')['overtime_minutes']);
        self::assertSame(0, $reports->week('2026-08-01')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-07')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-08')['overtime_minutes']);

        $this->putJson('/jours/2026-07-31', ['start_time' => '07:45', 'driving' => '09:00', 'warehouse' => '', 'meal_mode' => 'auto', 'meal_amount' => ''])->assertOk();
        $week = $reports->week('2026-07-27');
        self::assertSame(120, $week['overtime_minutes']);
        self::assertSame(120, $week['overtime_25_minutes']);
        self::assertSame(0, $week['overtime_50_minutes']);
        self::assertSame(120, $reports->month('2026-07')['overtime_minutes']);
        self::assertSame(0, $reports->month('2026-08')['overtime_minutes']);
    }

    public function test_august_31_does_not_contribute_to_september_overtime(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-31',
            'driving_minutes' => 600,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        foreach (['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => 540,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $reports = app(ReportService::class);
        self::assertSame(0, $reports->month('2026-08')['overtime_minutes']);
        self::assertSame(60, $reports->month('2026-09')['overtime_minutes']);
        self::assertSame(0, $reports->week('2026-08-31')['overtime_minutes']);
        self::assertSame(60, $reports->week('2026-09-01')['overtime_25_minutes']);
        self::assertSame(0, $reports->week('2026-09-01')['overtime_50_minutes']);
    }

    public function test_overtime_amount_applies_25_then_50_percent_each_week(): void
    {
        $this->post('/parametres', [
            'effective_from' => '2026-08-01',
            'default_start_time' => '07:45',
            'hourly_net_rate' => '10',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres');

        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => 540,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $reports = app(ReportService::class);
        $week = $reports->week('2026-08-03');
        self::assertSame(480, $week['overtime_25_minutes']);
        self::assertSame(120, $week['overtime_50_minutes']);
        self::assertSame(13000, $week['overtime_net_cents']);

        $month = $reports->month('2026-08');
        self::assertSame(35000, $month['normal_net_cents']);
        self::assertSame(45000, $month['work_net_cents']);
        self::assertSame(13000, $month['overtime_net_cents']);

        $this->get('/mois/2026-08')
            ->assertOk()
            ->assertSee('Heures sup +25 %')
            ->assertSee('Heures sup +50 %')
            ->assertSee('130,00 €');
    }

    public function test_meal_validation_and_forced_override(): void
    {
        $this->putJson('/jours/2026-08-03', ['start_time' => '07:45', 'driving' => '05:30', 'warehouse' => '01:00', 'meal_mode' => 'auto', 'meal_amount' => ''])->assertOk();
        self::assertSame(1600, app(ReportService::class)->month('2026-08')['meal_cents']);

        $this->putJson('/jours/2026-08-03', ['start_time' => '07:45', 'driving' => '05:15', 'warehouse' => '01:00', 'meal_mode' => 'forced', 'meal_amount' => '8,50'])->assertOk();
        self::assertSame(850, app(ReportService::class)->month('2026-08')['meal_cents']);

        $this->putJson('/jours/2026-08-04', ['start_time' => '07:45', 'driving' => '14:75', 'warehouse' => '', 'meal_mode' => 'auto'])->assertUnprocessable();
        $this->putJson('/jours/2026-08-04', ['start_time' => '07:45', 'driving' => '20:00', 'warehouse' => '05:00', 'meal_mode' => 'auto'])->assertUnprocessable();
    }

    public function test_payment_fifo_keeps_history_and_monthly_global_balances(): void
    {
        foreach (['2026-07-27','2026-07-28','2026-07-29','2026-07-30','2026-07-31','2026-08-01','2026-08-02'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 360, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }
        foreach (['2026-08-03','2026-08-04','2026-08-05','2026-08-06','2026-08-07','2026-08-08','2026-08-09'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 360, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }

        $beforeJuly = app(ReportService::class)->month('2026-07')['overtime_net_cents'];
        $beforeAugust = app(ReportService::class)->month('2026-08')['overtime_net_cents'];
        self::assertGreaterThan(0, $beforeJuly + $beforeAugust);

        $this->post('/paiements', ['payment_date' => '2026-10-15', 'amount' => '50,00', 'hours_paid' => '', 'note' => 'partiel'])->assertRedirect('/paiements');
        self::assertSame(1, OvertimePayment::query()->count());
        $payment = OvertimePayment::query()->firstOrFail();
        self::assertSame(5000, $payment->amount_cents);
        self::assertNotEmpty(app(ReportService::class)->paymentAllocations()[$payment->id] ?? []);

        self::assertSame($beforeJuly, app(ReportService::class)->month('2026-07')['overtime_net_cents']);
        self::assertSame($beforeAugust, app(ReportService::class)->month('2026-08')['overtime_net_cents']);
        self::assertSame(5000, app(ReportService::class)->balance()['paid']);

        WorkDay::query()->where('date', '2026-07-31')->delete();
        $after = app(ReportService::class)->balance();
        self::assertSame(5000, $after['paid']);
        self::assertSame(max(0, $after['generated'] - 5000), $after['remaining']);
    }

    public function test_settings_are_effective_dated_without_rewriting_history(): void
    {
        WorkDay::query()->create(['date' => '2026-08-01', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        $old = app(ReportService::class)->month('2026-08')['work_net_cents'];
        self::assertSame(974, $old);

        $this->post('/parametres', ['effective_from' => '2026-08-15', 'default_start_time' => '08:30', 'hourly_net_rate' => '12', 'weekly_threshold' => '35:00', 'meal_allowance' => '16', 'meal_allowance_time' => '14:15'])->assertRedirect('/parametres');
        WorkDay::query()->create(['date' => '2026-08-16', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        $month = app(ReportService::class)->month('2026-08');
        self::assertSame(2174, $month['work_net_cents']);
        self::assertSame(2, SettingPeriod::query()->count());
    }

    public function test_configured_meal_amount_is_used_at_exact_threshold(): void
    {
        $this->post('/parametres', [
            'effective_from' => '2026-08-01',
            'default_start_time' => '07:45',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16,31',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres');

        $this->putJson('/jours/2026-08-03', [
            'start_time' => '07:45',
            'driving' => '05:30',
            'warehouse' => '01:00',
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ])->assertOk();

        self::assertSame(1631, app(ReportService::class)->month('2026-08')['meal_cents']);

        $response = $this->get('/mois/2026-08')->assertOk();
        $response->assertSee('data-meal-default="1631"', false);
        $response->assertDontSee('Automatique (16,00 €)', false);
    }

    public function test_default_start_time_is_effective_dated_and_used_for_empty_days(): void
    {
        $this->post('/parametres', [
            'effective_from' => '2026-08-15',
            'default_start_time' => '08:30',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres');

        $content = $this->get('/mois/2026-08')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/data-date="2026-08-14".*?name="start_time" value="07:45"/s', $content);
        self::assertMatchesRegularExpression('/data-date="2026-08-17".*?name="start_time" value="08:30"/s', $content);

        $this->putJson('/jours/2026-08-17', [
            'start_time' => '08:30',
            'driving' => '',
            'warehouse' => '',
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ])->assertOk();
        self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-17')->exists());

        self::assertSame(510, SettingPeriod::query()->whereDate('effective_from', '2026-08-15')->firstOrFail()->default_start_time_minutes);
    }

    public function test_payment_hours_and_amount_can_be_entered_independently(): void
    {
        foreach (['2026-08-03','2026-08-04','2026-08-05','2026-08-06','2026-08-07'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }
        $this->post('/paiements', [
            'payment_date' => '2026-08-13',
            'amount' => '13',
            'hours_paid' => '01:00',
        ])->assertRedirect('/paiements');

        $payment = OvertimePayment::query()->firstOrFail();
        self::assertSame(1300, $payment->amount_cents);
        self::assertSame(60, $payment->hours_paid_minutes);
        self::assertSame(240, app(ReportService::class)->balance()['remaining_minutes_indicative']);
    }

    public function test_rest_day_ignores_work_fields_that_are_no_longer_relevant(): void
    {
        $this->putJson('/jours/2026-08-03', [
            'start_time' => 'invalide',
            'driving' => 'invalide',
            'warehouse' => 'invalide',
            'is_rest' => true,
            'meal_mode' => 'invalide',
            'meal_amount' => 'invalide',
        ])->assertOk();

        $day = WorkDay::query()->whereDate('date', '2026-08-03')->firstOrFail();
        self::assertTrue($day->is_rest);
        self::assertSame(0, $day->driving_minutes);
        self::assertSame(0, $day->warehouse_minutes);
    }

    public function test_payment_hours_can_exceed_calculated_remaining_overtime(): void
    {
        foreach (['2026-08-03','2026-08-04','2026-08-05','2026-08-06','2026-08-07'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }

        self::assertSame(300, app(ReportService::class)->balance()['remaining_minutes_indicative']);

        $this->from('/paiements')->post('/paiements', [
            'payment_date' => '2026-08-13',
            'amount' => '13',
            'hours_paid' => '05:01',
        ])->assertRedirect('/paiements')->assertSessionHasNoErrors();

        self::assertSame(1, OvertimePayment::query()->count());
        self::assertSame(301, OvertimePayment::query()->firstOrFail()->hours_paid_minutes);
    }

    public function test_whole_hours_are_accepted_for_work_days_and_overtime_payments(): void
    {
        $this->putJson('/jours/2026-08-03', [
            'start_time' => '7',
            'driving' => '6',
            'warehouse' => '1',
            'is_rest' => false,
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ])->assertOk();

        $day = WorkDay::query()->whereDate('date', '2026-08-03')->firstOrFail();
        self::assertSame(420, $day->start_time_minutes);
        self::assertSame(360, $day->driving_minutes);
        self::assertSame(60, $day->warehouse_minutes);

        foreach (['2026-08-04','2026-08-05','2026-08-06','2026-08-07'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        }

        $this->post('/paiements', [
            'payment_date' => '2026-08-13',
            'amount' => '13',
            'hours_paid' => '1',
        ])->assertRedirect('/paiements');

        self::assertSame(60, OvertimePayment::query()->latest('id')->firstOrFail()->hours_paid_minutes);
    }

    public function test_payment_and_settings_dates_stay_inside_supported_range(): void
    {
        $this->from('/paiements')->post('/paiements', [
            'payment_date' => '1999-12-31',
            'amount' => '10',
            'hours_paid' => '',
        ])->assertRedirect('/paiements')->assertSessionHasErrors('payment_date');
        self::assertSame(0, OvertimePayment::query()->count());

        $this->from('/parametres')->post('/parametres', [
            'effective_from' => '2201-01-01',
            'default_start_time' => '07:45',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres')->assertSessionHasErrors('effective_from');
        self::assertSame(1, SettingPeriod::query()->count());
    }

    public function test_hourly_net_rate_must_be_positive(): void
    {
        $this->from('/parametres')->post('/parametres', [
            'effective_from' => '2026-08-15',
            'default_start_time' => '07:45',
            'hourly_net_rate' => '0',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '16',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres')->assertSessionHasErrors([
            'hourly_net_rate' => 'Le taux horaire net doit être supérieur à 0 €.',
        ]);

        self::assertSame(1, SettingPeriod::query()->count());
    }

    public function test_month_total_excludes_overtime_but_keeps_it_visible_separately(): void
    {
        foreach (['2026-08-03','2026-08-04','2026-08-05','2026-08-06','2026-08-07'] as $date) {
            $this->putJson('/jours/'.$date, [
                'start_time' => '07:45',
                'driving' => '08:00',
                'warehouse' => '',
                'is_rest' => false,
                'meal_mode' => 'auto',
                'meal_amount' => '',
            ])->assertOk();
        }

        $month = app(ReportService::class)->month('2026-08');
        self::assertGreaterThan(0, $month['overtime_net_cents']);
        self::assertSame($month['normal_net_cents'] + $month['meal_cents'], $month['theoretical_net_cents']);
        self::assertNotSame($month['work_net_cents'] + $month['meal_cents'], $month['theoretical_net_cents']);

        $response = $this->get('/mois/2026-08')->assertOk();
        $response->assertSee('Total hors heures sup');
        $response->assertSee('Montant heures sup');
        $response->assertSee('fa-table-columns', false);
    }

    public function test_rest_days_and_fill_states_are_distinct(): void
    {
        $html = $this->get('/mois/2026-08')->assertOk()->getContent();
        self::assertMatchesRegularExpression('/class="work-row row-rest" data-date="2026-08-02"[^>]*data-is-rest="1"/', $html);
        self::assertMatchesRegularExpression('/class="work-row row-needs-fill" data-date="2026-08-03"[^>]*data-is-rest="0"/', $html);

        $this->putJson('/jours/2026-08-02', [
            'start_time' => '07:45',
            'driving' => '',
            'warehouse' => '',
            'is_rest' => false,
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ])->assertOk();

        $sunday = WorkDay::query()->whereDate('date', '2026-08-02')->firstOrFail();
        self::assertFalse($sunday->is_rest);
        $html = $this->get('/mois/2026-08')->getContent();
        self::assertMatchesRegularExpression('/class="work-row row-needs-fill" data-date="2026-08-02"[^>]*data-is-rest="0"/', $html);

        $this->putJson('/jours/2026-08-03', [
            'start_time' => '09:00',
            'driving' => '08:00',
            'warehouse' => '02:00',
            'is_rest' => false,
            'meal_mode' => 'forced',
            'meal_amount' => '25',
        ])->assertOk();

        $this->putJson('/jours/2026-08-03', [
            'start_time' => '09:00',
            'driving' => '08:00',
            'warehouse' => '02:00',
            'is_rest' => true,
            'meal_mode' => 'forced',
            'meal_amount' => '25',
        ])->assertOk();

        $rest = WorkDay::query()->whereDate('date', '2026-08-03')->firstOrFail();
        self::assertTrue($rest->is_rest);
        self::assertSame(480, $rest->driving_minutes);
        self::assertSame(120, $rest->warehouse_minutes);
        self::assertSame('forced', $rest->meal_allowance_mode);
        self::assertSame(2500, $rest->meal_allowance_forced_cents);
        self::assertSame(0, app(ReportService::class)->month('2026-08')['worked_minutes']);

        $html = $this->get('/mois/2026-08')->getContent();
        self::assertMatchesRegularExpression('/class="work-row row-rest" data-date="2026-08-03"[^>]*data-is-rest="1"/', $html);
        self::assertMatchesRegularExpression('/name="driving" value="08:00"[^>]*disabled/', $html);

        $this->putJson('/jours/2026-08-03', [
            'start_time' => '09:00',
            'driving' => '08:00',
            'warehouse' => '02:00',
            'is_rest' => false,
            'meal_mode' => 'forced',
            'meal_amount' => '25',
        ])->assertOk();
        self::assertSame(600, app(ReportService::class)->month('2026-08')['worked_minutes']);

        $this->putJson('/jours/2026-08-04', [
            'start_time' => '07:45',
            'driving' => '01:00',
            'warehouse' => '',
            'is_rest' => false,
            'meal_mode' => 'auto',
            'meal_amount' => '',
        ])->assertOk();
        $html = $this->get('/mois/2026-08')->getContent();
        self::assertMatchesRegularExpression('/class="work-row row-filled" data-date="2026-08-04"[^>]*data-is-rest="0"/', $html);
    }

    public function test_day_deletion_endpoint_is_removed(): void
    {
        WorkDay::query()->create(['date' => '2026-08-03', 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        $this->deleteJson('/jours/2026-08-03')->assertStatus(405);
        self::assertSame(1, WorkDay::query()->count());
        self::assertSame(60, WorkDay::query()->whereDate('date', '2026-08-03')->value('driving_minutes'));
    }
}
