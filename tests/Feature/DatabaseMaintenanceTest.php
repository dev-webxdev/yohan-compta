<?php

namespace Tests\Feature;

use App\Models\DocumentFolder;
use App\Models\LibraryDocument;
use App\Models\MonthlySalary;
use App\Models\WorkDay;
use App\Services\DatabaseMaintenanceService;
use App\Services\LibraryService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class DatabaseMaintenanceTest extends TestCase
{
    private string $databasePath;
    private string $originalDatabase;
    private string $libraryRoot;
    private string $originalLocalRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDatabase = (string) config('database.connections.sqlite.database');
        $this->originalLocalRoot = (string) config('filesystems.disks.local.root');
        $this->libraryRoot = storage_path('framework/testing/maintenance-library-'.bin2hex(random_bytes(5)));
        config(['filesystems.disks.local.root' => $this->libraryRoot]);
        $directory = storage_path('framework/testing');
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        $this->databasePath = $directory.'/maintenance-'.bin2hex(random_bytes(6)).'.sqlite';
        touch($this->databasePath);

        config(['database.connections.sqlite.database' => $this->databasePath]);
        DB::purge('sqlite');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::purge('sqlite');
        foreach (glob($this->databasePath.'*') ?: [] as $path) {
            @unlink($path);
        }

        File::deleteDirectory($this->libraryRoot);
        config([
            'database.connections.sqlite.database' => $this->originalDatabase,
            'filesystems.disks.local.root' => $this->originalLocalRoot,
        ]);
        DB::purge('sqlite');
        parent::tearDown();
    }

    public function test_settings_page_exposes_all_database_maintenance_actions(): void
    {
        $response = $this->get('/parametres')->assertOk();

        $response->assertSee('Sauvegarder toutes les données')
            ->assertSee('Restaurer une sauvegarde')
            ->assertSee(route('settings.database.backup'), false)
            ->assertSee(route('settings.database.restore'), false)
            ->assertDontSee('Sauvegardes de sécurité')
            ->assertDontSee('Sauvegarde automatique')
            ->assertDontSee('Réinitialiser complètement le site')
            ->assertDontSee('Réinitialiser un mois');

        $this->delete('/parametres/base')->assertNotFound();
        $this->delete('/parametres/base/mois')->assertNotFound();
        $this->get('/parametres/base/sauvegarde-automatique')->assertNotFound();
    }

    public function test_download_backup_is_a_consistent_sqlite_copy_with_current_data(): void
    {
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 60,
            'meal_allowance_mode' => 'auto',
        ]);
        MonthlySalary::query()->create([
            'month' => '2026-08',
            'net_amount_cents' => 185000,
            'note' => 'Sauvegarde salaire',
        ]);

        $copy = app(DatabaseMaintenanceService::class)->createDownloadCopy();
        try {
            self::assertFileExists($copy);
            $pdo = new PDO('sqlite:'.$copy);
            self::assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM work_days WHERE date = '2026-08-14'")->fetchColumn());
            self::assertSame(185000, (int) $pdo->query("SELECT net_amount_cents FROM monthly_salaries WHERE month = '2026-08'")->fetchColumn());
            self::assertSame('ok', $pdo->query('PRAGMA integrity_check')->fetchColumn());
        } finally {
            @unlink($copy);
        }

        $response = $this->get('/parametres/base/sauvegarde')->assertOk();
        self::assertStringContainsString('attachment;', (string) $response->headers->get('content-disposition'));
        self::assertStringContainsString('.zip', (string) $response->headers->get('content-disposition'));
    }

    public function test_restore_replaces_database_without_persisting_a_safety_backup(): void
    {
        WorkDay::query()->create([
            'date' => '2026-07-01',
            'driving_minutes' => 420,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $sourceBackup = app(DatabaseMaintenanceService::class)->createDownloadCopy();

        WorkDay::query()->delete();
        WorkDay::query()->create([
            'date' => '2026-08-14',
            'driving_minutes' => 480,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        try {
            app(DatabaseMaintenanceService::class)->restoreFrom($sourceBackup);
            self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
            self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-14')->exists());
        } finally {
            @unlink($sourceBackup);
        }
    }

    public function test_sqlite_restore_preserves_current_library(): void
    {
        WorkDay::query()->create([
            'date' => '2026-07-01',
            'driving_minutes' => 420,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        $sourceBackup = app(DatabaseMaintenanceService::class)->createDownloadCopy();

        $folder = DocumentFolder::query()->create(['name' => 'Documents actuels']);
        $document = app(LibraryService::class)->storeDocument(
            $folder->id,
            UploadedFile::fake()->createWithContent('actuel.txt', 'a-conserver'),
        );
        WorkDay::query()->delete();

        try {
            app(DatabaseMaintenanceService::class)->restoreFrom($sourceBackup);

            self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
            self::assertSame('Documents actuels', DocumentFolder::query()->findOrFail($folder->id)->name);
            $restoredDocument = LibraryDocument::query()->findOrFail($document->id);
            self::assertSame('actuel.txt', $restoredDocument->original_name);
            self::assertSame('a-conserver', file_get_contents(app(LibraryService::class)->documentPath($restoredDocument)));
        } finally {
            @unlink($sourceBackup);
        }
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
    public function test_complete_backup_round_trip_restores_library_files(): void
    {
        $folder = DocumentFolder::query()->create(['name' => '2026']);
        $document = app(LibraryService::class)->storeDocument(
            $folder->id,
            UploadedFile::fake()->createWithContent('bulletin.txt', 'contenu-sauvegarde'),
        );
        $archive = app(DatabaseMaintenanceService::class)->createApplicationBackup();

        try {
            self::assertFileExists($archive);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive) === true);
            self::assertNotFalse($zip->locateName('database.sqlite'));
            self::assertNotFalse($zip->locateName('library/'.$document->storage_name));
            $zip->close();

            app(LibraryService::class)->deleteFolder($folder);
            self::assertDatabaseCount('document_folders', 0);
            self::assertDatabaseCount('library_documents', 0);

            app(DatabaseMaintenanceService::class)->restoreFrom($archive);

            $restoredFolder = DocumentFolder::query()->where('name', '2026')->firstOrFail();
            $restored = LibraryDocument::query()->where('folder_id', $restoredFolder->id)->firstOrFail();
            $path = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$restored->storage_name;
            self::assertFileExists($path);
            self::assertSame('contenu-sauvegarde', file_get_contents($path));
        } finally {
            @unlink($archive);
        }
    }

}
