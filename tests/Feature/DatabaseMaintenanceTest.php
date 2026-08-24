<?php

namespace Tests\Feature;

use App\Models\DocumentFolder;
use App\Models\DocumentLink;
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
            ->assertSee('Sauvegardes de sécurité')
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

    public function test_restore_replaces_database_and_persists_a_complete_safety_backup(): void
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
        $folder = DocumentFolder::query()->create(['name' => 'Avant restauration']);
        $document = app(LibraryService::class)->storeDocument(
            $folder->id,
            UploadedFile::fake()->image('avant.png', 22, 22),
        );
        $expectedImage = file_get_contents(app(LibraryService::class)->documentPath($document));

        try {
            $safetyBackup = app(DatabaseMaintenanceService::class)->restoreFrom($sourceBackup);
            self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
            self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-14')->exists());
            self::assertFileExists($safetyBackup);

            $zip = new ZipArchive();
            self::assertTrue($zip->open($safetyBackup) === true);
            self::assertNotFalse($zip->locateName('database.sqlite'));
            self::assertNotFalse($zip->locateName('library/'.$document->storage_name));
            self::assertSame($expectedImage, $zip->getFromName('library/'.$document->storage_name));
            $zip->close();

            $backups = app(DatabaseMaintenanceService::class)->safetyBackups();
            self::assertSame(basename($safetyBackup), $backups[0]['name']);
            $this->get('/parametres')->assertOk()->assertSee(basename($safetyBackup));
            $download = $this->get(route('settings.database.backups.download', ['backup' => basename($safetyBackup)]))->assertOk();
            self::assertStringContainsString(
                basename($safetyBackup),
                (string) $download->headers->get('content-disposition'),
            );
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
            UploadedFile::fake()->image('actuel.png', 24, 24),
        );
        $expectedImage = file_get_contents(app(LibraryService::class)->documentPath($document));
        WorkDay::query()->delete();

        try {
            app(DatabaseMaintenanceService::class)->restoreFrom($sourceBackup);

            self::assertTrue(WorkDay::query()->whereDate('date', '2026-07-01')->exists());
            self::assertSame('Documents actuels', DocumentFolder::query()->findOrFail($folder->id)->name);
            $restoredDocument = LibraryDocument::query()->findOrFail($document->id);
            self::assertSame('actuel.png', $restoredDocument->original_name);
            self::assertSame($expectedImage, file_get_contents(app(LibraryService::class)->documentPath($restoredDocument)));
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
            UploadedFile::fake()->image('bulletin.png', 32, 24),
        );
        $expectedImage = file_get_contents(app(LibraryService::class)->documentPath($document));
        $archive = app(DatabaseMaintenanceService::class)->createApplicationBackup();

        try {
            self::assertFileExists($archive);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($archive) === true);
            self::assertNotFalse($zip->locateName('database.sqlite'));
            self::assertNotFalse($zip->locateName('library/'.$document->storage_name));
            $zip->close();

            app(LibraryService::class)->trashFolder($folder);
            app(LibraryService::class)->forceDeleteFolder(DocumentFolder::onlyTrashed()->findOrFail($folder->id));
            self::assertSame(0, DocumentFolder::withTrashed()->count());
            self::assertSame(0, LibraryDocument::withTrashed()->count());

            app(DatabaseMaintenanceService::class)->restoreFrom($archive);

            $restoredFolder = DocumentFolder::query()->where('name', '2026')->firstOrFail();
            $restored = LibraryDocument::query()->where('folder_id', $restoredFolder->id)->firstOrFail();
            $path = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$restored->storage_name;
            self::assertFileExists($path);
            self::assertSame($expectedImage, file_get_contents($path));
        } finally {
            @unlink($archive);
        }
    }

    public function test_complete_backup_restores_trash_state_and_trashed_file(): void
    {
        $folder = DocumentFolder::query()->create(['name' => 'Corbeille sauvegardée']);
        $document = app(LibraryService::class)->storeDocument(
            $folder->id,
            UploadedFile::fake()->image('supprimee.png', 20, 20),
        );
        $path = app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name;
        app(LibraryService::class)->trashDocument($document);
        self::assertTrue(LibraryDocument::onlyTrashed()->whereKey($document->id)->exists());
        self::assertFileExists($path);

        $archive = app(DatabaseMaintenanceService::class)->createApplicationBackup();
        try {
            app(LibraryService::class)->forceDeleteDocument(LibraryDocument::onlyTrashed()->findOrFail($document->id));
            self::assertFileDoesNotExist($path);

            app(DatabaseMaintenanceService::class)->restoreFrom($archive);

            $restored = LibraryDocument::onlyTrashed()->where('original_name', 'supprimee.png')->firstOrFail();
            self::assertSame($folder->id, $restored->folder_id);
            self::assertFileExists(app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$restored->storage_name);
            $this->get('/bibliotheque/corbeille')->assertOk()->assertSee('supprimee.png');
        } finally {
            @unlink($archive);
        }
    }

    public function test_safety_backup_can_restore_the_complete_pre_restore_state(): void
    {
        $targetFolder = DocumentFolder::query()->create(['name' => 'Etat cible']);
        app(LibraryService::class)->storeDocument(
            $targetFolder->id,
            UploadedFile::fake()->image('cible.png', 28, 20),
        );
        WorkDay::query()->create([
            'date' => '2026-08-20',
            'driving_minutes' => 480,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);

        $replacement = app(DatabaseMaintenanceService::class)->createApplicationBackup();
        try {
            app(LibraryService::class)->trashFolder($targetFolder);
            app(LibraryService::class)->forceDeleteFolder(DocumentFolder::onlyTrashed()->findOrFail($targetFolder->id));
            WorkDay::query()->delete();

            $currentParent = DocumentFolder::query()->create(['name' => 'Etat avant restauration']);
            $currentChild = DocumentFolder::query()->create([
                'parent_id' => $currentParent->id,
                'name' => 'Sous-dossier',
            ]);
            $currentDocument = app(LibraryService::class)->storeDocument(
                $currentChild->id,
                UploadedFile::fake()->image('avant-restauration.png', 31, 23),
            );
            $expectedImage = file_get_contents(app(LibraryService::class)->documentPath($currentDocument));
            WorkDay::query()->create([
                'date' => '2026-08-21',
                'driving_minutes' => 420,
                'warehouse_minutes' => 30,
                'meal_allowance_mode' => 'auto',
            ]);

            $safety = app(DatabaseMaintenanceService::class)->restoreFrom($replacement);
            self::assertFileExists($safety);
            self::assertTrue(WorkDay::query()->whereDate('date', '2026-08-20')->exists());
            self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-21')->exists());
            self::assertTrue(DocumentFolder::query()->where('name', 'Etat cible')->exists());

            $this->post(
                route('settings.database.backups.restore', ['backup' => basename($safety)]),
                ['confirmed' => '1'],
            )->assertRedirect(route('settings.index'));

            self::assertFalse(WorkDay::query()->whereDate('date', '2026-08-20')->exists());
            self::assertTrue(WorkDay::query()->whereDate('date', '2026-08-21')->exists());
            $restoredParent = DocumentFolder::query()->where('name', 'Etat avant restauration')->firstOrFail();
            $restoredChild = DocumentFolder::query()->where('parent_id', $restoredParent->id)->where('name', 'Sous-dossier')->firstOrFail();
            $restoredDocument = LibraryDocument::query()->where('folder_id', $restoredChild->id)->firstOrFail();
            self::assertSame('avant-restauration.png', $restoredDocument->original_name);
            self::assertSame(
                $expectedImage,
                file_get_contents(app(LibraryService::class)->documentPath($restoredDocument)),
            );
            self::assertSame($safety, app(DatabaseMaintenanceService::class)->safetyBackupPath(basename($safety)));
            self::assertGreaterThanOrEqual(2, count(app(DatabaseMaintenanceService::class)->safetyBackups()));
        } finally {
            @unlink($replacement);
        }
    }

    public function test_archive_restore_rejects_path_traversal_before_creating_a_safety_backup(): void
    {
        $archive = app(DatabaseMaintenanceService::class)->createApplicationBackup();
        $escapePath = dirname($this->libraryRoot).DIRECTORY_SEPARATOR.'restore-escape-'.bin2hex(random_bytes(4)).'.txt';

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive) === true);
        self::assertTrue($zip->addFromString('../'.basename($escapePath), 'escape'));
        self::assertTrue($zip->close());

        try {
            app(DatabaseMaintenanceService::class)->restoreFrom($archive);
            self::fail('A path-traversal archive should have been rejected.');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('non sécurisé', $error->getMessage());
            self::assertFileDoesNotExist($escapePath);
            self::assertSame([], app(DatabaseMaintenanceService::class)->safetyBackups());
        } finally {
            @unlink($archive);
            @unlink($escapePath);
        }
    }

    public function test_safety_backup_path_rejects_traversal(): void
    {
        $this->expectException(RuntimeException::class);
        app(DatabaseMaintenanceService::class)->safetyBackupPath('../database.sqlite');
    }

    public function test_complete_backup_restores_planning_and_document_associations(): void
    {
        $folder = DocumentFolder::query()->create(['name' => 'Documents liés']);
        $document = app(LibraryService::class)->storeDocument(
            $folder->id,
            UploadedFile::fake()->image('justificatif.png', 20, 20),
        );
        WorkDay::query()->create([
            'date' => '2026-08-22',
            'planned_minutes' => 450,
            'is_leave' => true,
            'driving_minutes' => 0,
            'warehouse_minutes' => 0,
            'meal_allowance_mode' => 'auto',
        ]);
        DocumentLink::query()->create([
            'document_id' => $document->id,
            'target_type' => 'day',
            'target_key' => '2026-08-22',
        ]);

        $archive = app(DatabaseMaintenanceService::class)->createApplicationBackup();
        try {
            WorkDay::query()->whereDate('date', '2026-08-22')->update([
                'planned_minutes' => null,
                'is_leave' => false,
            ]);
            DocumentLink::query()->delete();

            app(DatabaseMaintenanceService::class)->restoreFrom($archive);

            $restoredDay = WorkDay::query()->whereDate('date', '2026-08-22')->firstOrFail();
            self::assertSame(450, $restoredDay->planned_minutes);
            self::assertTrue($restoredDay->is_leave);
            self::assertDatabaseHas('document_links', [
                'document_id' => $document->id,
                'target_type' => 'day',
                'target_key' => '2026-08-22',
            ]);
            self::assertFileExists(
                app(LibraryService::class)->libraryPath().DIRECTORY_SEPARATOR.$document->storage_name,
            );
        } finally {
            @unlink($archive);
        }
    }

}
