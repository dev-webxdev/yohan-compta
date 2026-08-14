<?php

namespace Tests\Feature;

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
            ->assertSee(route('settings.database.backup'), false)
            ->assertSee(route('settings.database.restore'), false)
            ->assertSee('Sauvegardes de sécurité')
            ->assertDontSee('Sauvegarde automatique')
            ->assertDontSee('Réinitialiser complètement le site')
            ->assertDontSee('Réinitialiser un mois');

        $this->delete('/parametres/base')->assertNotFound();
        $this->delete('/parametres/base/mois')->assertNotFound();
        $this->get('/parametres/base/sauvegarde-automatique')->assertNotFound();
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

        $beforeDownload = app(DatabaseMaintenanceService::class)->backups();
        $response = $this->get('/parametres/base/sauvegarde')->assertOk();
        self::assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        self::assertStringContainsString('.sqlite', (string) $response->headers->get('content-disposition'));
        self::assertCount(count($beforeDownload), app(DatabaseMaintenanceService::class)->backups());
    }

    public function test_saved_backups_are_listed_downloadable_and_deletable_with_confirmation(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'auto',
        ]);
        $backup = app(DatabaseMaintenanceService::class)->createBackup();
        $name = basename($backup);

        $response = $this->get('/parametres')->assertOk();
        $response->assertSee($name)
            ->assertSee(route('settings.database.backups.download', ['backup' => $name]), false)
            ->assertSee(route('settings.database.backups.restore', ['backup' => $name]), false)
            ->assertSee(route('settings.database.backups.delete', ['backup' => $name]), false)
            ->assertSee('Télécharger')
            ->assertSee('Restaurer')
            ->assertSee('Supprimer')
            ->assertSee('sans créer de nouvelle sauvegarde de sécurité')
            ->assertSee(route('settings.database.backups.delete-all'), false)
            ->assertSee('Tout supprimer')
            ->assertSee('data-confirm-title="Supprimer cette sauvegarde ?"', false)
            ->assertSee('data-confirm-danger="1"', false);

        $this->get(route('settings.database.backups.download', ['backup' => $name]))
            ->assertOk()
            ->assertDownload($name);
        self::assertFileExists($backup);

        $this->from('/parametres')
            ->delete(route('settings.database.backups.delete', ['backup' => $name]), ['confirmed' => ''])
            ->assertRedirect('/parametres')
            ->assertSessionHasErrors('confirmed');
        self::assertFileExists($backup);

        $this->delete(route('settings.database.backups.delete', ['backup' => $name]), ['confirmed' => '1'])
            ->assertRedirect('/parametres');
        self::assertFileDoesNotExist($backup);
    }

    public function test_saved_backup_can_restore_previous_state_without_creating_another_backup(): void
    {
        WorkDay::query()->create([
            'date' => '2026-07-01',
            'driving_minutes' => 420,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $backup = app(DatabaseMaintenanceService::class)->createBackup();
        $name = basename($backup);

        WorkDay::query()->delete();
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $before = glob(storage_path('app/private/backups/*.sqlite')) ?: [];

        $this->from('/parametres')
            ->post(route('settings.database.backups.restore', ['backup' => $name]), ['confirmed' => ''])
            ->assertRedirect('/parametres')
            ->assertSessionHasErrors('confirmed');
        self::assertTrue(WorkDay::query()->whereDate('date', '2026-08-14')->exists());

        $this->post(route('settings.database.backups.restore', ['backup' => $name]), ['confirmed' => '1'])
            ->assertRedirect('/parametres');

        self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
        self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-14')->exists());
        $after = glob(storage_path('app/private/backups/*.sqlite')) ?: [];
        self::assertCount(count($before), $after);
        self::assertFileExists($backup);
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
}
