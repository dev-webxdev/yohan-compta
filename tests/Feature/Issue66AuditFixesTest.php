<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Models\SettingPeriod;
use App\Models\WorkDay;
use App\Services\DatabaseMaintenanceService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class Issue66AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_worked_minutes_never_generate_an_automatic_meal(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-03',
            'start_time_minutes' => 900,
            'driving_minutes' => 0,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        self::assertSame(0, app(ReportService::class)->month('2026-08')['meal_cents']);
        $this->get('/mois/2026-08')->assertOk()->assertSee('data-date="2026-08-03"', false);
    }

    public function test_month_overtime_amount_is_the_sum_of_displayed_week_amounts(): void
    {
        foreach ([
            '2026-08-03' => 720, '2026-08-04' => 720, '2026-08-05' => 661,
            '2026-08-10' => 720, '2026-08-11' => 720, '2026-08-12' => 661,
        ] as $date => $minutes) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => $minutes,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $reports = app(ReportService::class);
        $first = $reports->week('2026-08-03')['overtime_net_cents'];
        $second = $reports->week('2026-08-10')['overtime_net_cents'];
        self::assertSame(20, $first);
        self::assertSame(20, $second);
        self::assertSame($first + $second, $reports->month('2026-08')['overtime_net_cents']);
    }

    public function test_daily_overtime_rounding_never_creates_negative_amounts(): void
    {
        SettingPeriod::query()->whereDate('effective_from', '2000-01-01')->update([
            'hourly_net_rate_cents' => 25,
            'weekly_threshold_minutes' => 0,
        ]);
        foreach (range(3, 9) as $day) {
            WorkDay::query()->create([
                'date' => sprintf('2026-08-%02d', $day),
                'driving_minutes' => 1,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $week = app(ReportService::class)->week('2026-08-03');
        self::assertSame($week['overtime_net_cents'], array_sum($week['overtime_net_by_date']));
        foreach ($week['overtime_net_by_date'] as $cents) {
            self::assertGreaterThanOrEqual(0, $cents);
        }
    }

    public function test_future_payment_is_not_reported_as_received_before_its_date(): void
    {
        OvertimePayment::query()->create([
            'payment_date' => '2026-11-10',
            'amount_cents' => 5000,
        ]);

        $report = app(ReportService::class)->year(2026);
        self::assertSame(0, $report['months']['2026-11']['paid_received_cents']);
        self::assertSame(0, app(ReportService::class)->balance()['paid']);

        $this->get('/paiements')
            ->assertOk()
            ->assertSee('Futur')
            ->assertSee('Sera affecté à partir du 10/11/2026')
            ->assertDontSee('Avance non affectée');
    }

    public function test_explicit_paid_hours_surplus_is_preserved_as_hours_in_advance(): void
    {
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => 480,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        OvertimePayment::query()->create([
            'payment_date' => '2026-08-15',
            'amount_cents' => 100,
            'hours_paid_minutes' => 301,
        ]);

        $balance = app(ReportService::class)->balance();
        self::assertSame(1, $balance['unallocated_paid_minutes']);
        $this->get('/paiements')->assertOk()->assertSee('Heures payées en avance : 00:01');
    }

    public function test_indicative_payment_hours_follow_fifo_debt_ratio_instead_of_normal_rate(): void
    {
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $date) {
            WorkDay::query()->create([
                'date' => $date,
                'driving_minutes' => 480,
                'warehouse_minutes' => 0,
                'meal_allowance_mode' => 'auto',
            ]);
        }

        $debt = app(ReportService::class)->month('2026-08');
        $payment = OvertimePayment::query()->create([
            'payment_date' => '2026-08-15',
            'amount_cents' => intdiv($debt['overtime_net_cents'], 2),
        ]);

        $allocations = app(ReportService::class)->paymentHourAllocations();
        $minutes = array_sum(array_column($allocations[$payment->id], 'minutes'));
        self::assertGreaterThanOrEqual(149, $minutes);
        self::assertLessThanOrEqual(151, $minutes);
    }

    public function test_extreme_money_input_is_validation_error_instead_of_500(): void
    {
        $this->from('/paiements')->post('/paiements', [
            'payment_date' => '2026-08-15',
            'amount' => '999999999999999999999999999999',
            'hours_paid' => '',
        ])->assertRedirect('/paiements')->assertSessionHasErrors('amount');

        self::assertSame(0, OvertimePayment::query()->count());
    }

    public function test_settings_values_are_loaded_for_the_selected_effective_date(): void
    {
        SettingPeriod::query()->create([
            'effective_from' => '2026-09-15',
            'default_start_time_minutes' => 480,
            'hourly_net_rate_cents' => 1200,
            'weekly_threshold_minutes' => 2100,
            'meal_allowance_cents' => 1700,
            'meal_allowance_time_minutes' => 855,
        ]);

        $this->getJson('/parametres/valeurs?date=2026-08-01')
            ->assertOk()
            ->assertJson([
                'exact' => false,
                'hourly_net_rate' => '9,74',
                'meal_allowance' => '16',
            ]);

        $this->getJson('/parametres/valeurs?date=2026-09-15')
            ->assertOk()
            ->assertJson([
                'exact' => true,
                'hourly_net_rate' => '12',
                'meal_allowance' => '17',
            ]);
    }

    public function test_intermediate_setting_change_propagates_only_fields_not_explicitly_changed_later(): void
    {
        SettingPeriod::query()->create([
            'effective_from' => '2026-09-01',
            'default_start_time_minutes' => 465,
            'hourly_net_rate_cents' => 1000,
            'weekly_threshold_minutes' => 2100,
            'meal_allowance_cents' => 1600,
            'meal_allowance_time_minutes' => 855,
        ]);

        $this->post('/parametres', [
            'effective_from' => '2026-08-25',
            'default_start_time' => '07:45',
            'hourly_net_rate' => '9,74',
            'weekly_threshold' => '35:00',
            'meal_allowance' => '17',
            'meal_allowance_time' => '14:15',
        ])->assertRedirect('/parametres');

        $future = SettingPeriod::query()->whereDate('effective_from', '2026-09-01')->firstOrFail();
        self::assertSame(1700, $future->meal_allowance_cents);
        self::assertSame(1000, $future->hourly_net_rate_cents);
    }

    public function test_month_export_contains_overtime_details_and_next_day_marker(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-03',
            'start_time_minutes' => 1200,
            'driving_minutes' => 360,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $csv = $this->get('/mois/2026-08/export.csv')->assertOk()->streamedContent();
        self::assertStringContainsString('"HS +25 %";"Taux net (€)";"Montant HS net (€)"', $csv);
        self::assertStringContainsString('02:00 (+1 j)', $csv);
    }

    public function test_restore_rejects_sqlite_with_expected_tables_but_wrong_schema(): void
    {
        $path = storage_path('framework/testing/invalid-schema-'.bin2hex(random_bytes(4)).'.sqlite');
        $pdo = new PDO('sqlite:'.$path);
        foreach (['migrations', 'work_days', 'setting_periods', 'overtime_payments'] as $table) {
            $pdo->exec('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY)');
        }
        $pdo = null;

        try {
            app(DatabaseMaintenanceService::class)->validateDatabaseFile($path);
            self::fail('An incompatible application schema should be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('structure incompatible', $error->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_restore_rejects_backup_from_unknown_future_migration(): void
    {
        $path = storage_path('framework/testing/future-schema-'.bin2hex(random_bytes(4)).'.sqlite');
        $pdo = new PDO('sqlite:'.$path);
        $pdo->exec('CREATE TABLE migrations (id INTEGER PRIMARY KEY, migration TEXT NOT NULL, batch INTEGER NOT NULL)');
        $pdo->exec('CREATE TABLE work_days (id INTEGER PRIMARY KEY, date TEXT, driving_minutes INTEGER, warehouse_minutes INTEGER, meal_allowance_mode TEXT, meal_allowance_forced_cents INTEGER)');
        $pdo->exec('CREATE TABLE setting_periods (id INTEGER PRIMARY KEY, effective_from TEXT, hourly_net_rate_cents INTEGER, weekly_threshold_minutes INTEGER, meal_allowance_cents INTEGER, meal_allowance_time_minutes INTEGER)');
        $pdo->exec('CREATE TABLE overtime_payments (id INTEGER PRIMARY KEY, payment_date TEXT, amount_cents INTEGER)');
        $pdo->exec("INSERT INTO setting_periods (effective_from, hourly_net_rate_cents, weekly_threshold_minutes, meal_allowance_cents, meal_allowance_time_minutes) VALUES ('2000-01-01', 974, 2100, 1600, 855)");
        $pdo->exec("INSERT INTO migrations (migration, batch) VALUES ('2099_01_01_000000_future_schema', 999)");
        $pdo = null;

        try {
            app(DatabaseMaintenanceService::class)->validateDatabaseFile($path);
            self::fail('A backup from an unknown future migration should be rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('version plus récente', $error->getMessage());
        } finally {
            @unlink($path);
        }
    }
}
