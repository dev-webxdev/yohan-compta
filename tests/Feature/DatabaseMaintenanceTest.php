<?php

namespace Tests\Feature;

use App\Models\OvertimePayment;
use App\Models\SettingPeriod;
use App\Models\WorkDay;
use App\Services\DatabaseMaintenanceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class DatabaseMaintenanceTest extends TestCase
{
    private string $databasePath;
    private string $originalDatabase;
    /** @var array<int,string> */
    private array $existingBackups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabase = (string) config('database.connections.sqlite.database');
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $this->databasePath = $directory.'/maintenance-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->databasePath);

        config(['database.connections.sqlite.database' => $this->databasePath]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
        $this->existingBackups = glob(storage_path('app/private/backups/*.sqlite')) ?: [];
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach (glob($this->databasePath.'*') ?: [] as $path) {
            @unlink($path);
        }

        foreach (glob(storage_path('app/private/backups/*.sqlite')) ?: [] as $path) {
            if (!in_array($path, $this->existingBackups, true)) {
                @unlink($path);
            }
        }

        config(['database.connections.sqlite.database' => $this->originalDatabase]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_settings_page_exposes_all_database_maintenance_actions(): void
    {
        $response = $this->get('/parametres')->assertOk();

        $response->assertSee('Sauvegarder la BDD')
            ->assertSee('Restaurer une BDD SQLite')
            ->assertSee('Réinitialiser complètement le site')
            ->assertSee('Réinitialiser un mois')
            ->assertSee(route('settings.database.backup'), false)
            ->assertSee(route('settings.database.restore'), false)
            ->assertSee(route('settings.database.reset'), false)
            ->assertSee(route('settings.database.reset-month'), false);

        $this->from('/parametres')
            ->delete('/parametres/base', ['confirmed' => ''])
            ->assertRedirect('/parametres')
            ->assertSessionHasErrors('confirmed');

        $this->from('/parametres')
            ->delete('/parametres/base/mois', ['year' => 2026, 'month' => 8, 'confirmed' => ''])
            ->assertRedirect('/parametres')
            ->assertSessionHasErrors('confirmed');
    }

    public function test_backup_is_a_consistent_sqlite_copy_with_current_data(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'auto',
        ]);

        $backup = app(DatabaseMaintenanceService::class)->createBackup();

        self::assertFileExists($backup);
        $pdo = new PDO('sqlite:'.$backup);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM work_days WHERE date = '2026-08-14'")->fetchColumn());
        self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());

        $response = $this->get('/parametres/base/sauvegarde')->assertOk();
        self::assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        self::assertStringContainsString('.sqlite', (string) $response->headers->get('content-disposition'));
    }

    public function test_restore_replaces_database_and_keeps_a_pre_restore_backup(): void
    {
        WorkDay::query()->create([
            'date' => '2026-07-01',
            'driving_minutes' => 420,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $sourceBackup = app(DatabaseMaintenanceService::class)->createBackup();

        WorkDay::query()->delete();
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $safetyBackup = app(DatabaseMaintenanceService::class)->restoreFrom($sourceBackup);

        self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
        self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-14')->exists());
        self::assertFileExists($safetyBackup);

        $pdo = new PDO('sqlite:'.$safetyBackup);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM work_days WHERE date = '2026-08-14'")->fetchColumn());
    }

    public function test_invalid_restore_file_is_rejected_before_current_database_is_touched(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $invalid = storage_path('framework/testing/not-sqlite-'.bin2hex(random_bytes(4)).'.db');
        file_put_contents($invalid, 'not a sqlite database');

        try {
            app(DatabaseMaintenanceService::class)->restoreFrom($invalid);
            self::fail('Invalid SQLite file should have been rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('SQLite', $error->getMessage());
            self::assertTrue(WorkDay::query()->whereDate('date', '2026-08-14')->exists());
        } finally {
            @unlink($invalid);
        }
    }

    public function test_full_reset_restores_initial_state_after_creating_a_backup(): void
    {
        WorkDay::query()->create(['date' => '2026-08-14', 'driving_minutes' => 480, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
        OvertimePayment::query()->create(['payment_date' => '2026-08-14', 'amount_cents' => 5000]);
        SettingPeriod::query()->create([
            'effective_from' => '2026-08-01',
            'hourly_gross_rate_cents' => 1500,
            'hourly_net_rate_cents' => 1200,
            'weekly_threshold_minutes' => 2100,
            'meal_allowance_cents' => 1800,
            'meal_allowance_time_minutes' => 855,
        ]);

        $backup = app(DatabaseMaintenanceService::class)->resetAll();

        self::assertFileExists($backup);
        self::assertSame(0, WorkDay::query()->count());
        self::assertSame(0, OvertimePayment::query()->count());
        self::assertSame(1, SettingPeriod::query()->count());
        $default = SettingPeriod::query()->firstOrFail();
        self::assertSame('2000-01-01', $default->effective_from->format('Y-m-d'));
        self::assertSame(1231, $default->hourly_gross_rate_cents);
        self::assertSame(974, $default->hourly_net_rate_cents);

        $pdo = new PDO('sqlite:'.$backup);
        self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM work_days WHERE date = '2026-08-14'")->fetchColumn());
    }

    public function test_month_reset_only_removes_work_days_and_payments_from_selected_month(): void
    {
        foreach (['2026-07-31', '2026-08-01', '2026-08-31', '2026-09-01'] as $date) {
            WorkDay::query()->create(['date' => $date, 'driving_minutes' => 60, 'warehouse_minutes' => 0, 'meal_allowance_mode' => 'auto']);
            OvertimePayment::query()->create(['payment_date' => $date, 'amount_cents' => 100]);
        }

        $backup = app(DatabaseMaintenanceService::class)->resetMonth(2026, 8);

        self::assertFileExists($backup);
        self::assertSame(['2026-07-31', '2026-09-01'], WorkDay::query()->orderBy('date')->get()->map(fn (WorkDay $day): string => $day->date->format('Y-m-d'))->all());
        self::assertSame(['2026-07-31', '2026-09-01'], OvertimePayment::query()->orderBy('payment_date')->get()->map(fn (OvertimePayment $payment): string => $payment->payment_date->format('Y-m-d'))->all());
        self::assertSame(1, SettingPeriod::query()->count());
    }
}
