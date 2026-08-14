<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseMaintenanceService
{
    private const REQUIRED_TABLES = ['migrations', 'work_days', 'setting_periods', 'overtime_payments'];
    private const BACKUP_DIRECTORY = 'app/private/backups';
    private const BACKUP_FILENAME_PATTERN = '/^yohan-compta-\d{8}-\d{6}-[a-f0-9]{6}\.sqlite$/';

    public function createBackup(): string
    {
        $directory = $this->backupDirectory();
        File::ensureDirectoryExists($directory);

        $path = $directory.'/yohan-compta-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        return $this->createSnapshot($path);
    }

    public function createDownloadCopy(): string
    {
        return $this->createSnapshot(
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'yohan-compta-download-'.bin2hex(random_bytes(6)).'.sqlite',
        );
    }

    public function refreshAutomaticBackup(): ?string
    {
        try {
            $configuredDatabase = (string) config('database.connections.'.$this->connectionName().'.database');
            if ($configuredDatabase === ':memory:' || $configuredDatabase === '') {
                return null;
            }

            $target = $this->automaticBackupPath(false);
            File::ensureDirectoryExists(dirname($target));
            $temporary = dirname($target).'/.automatic-'.bin2hex(random_bytes(6)).'.sqlite';
            $this->createSnapshot($temporary);

            if (!@rename($temporary, $target)) {
                @unlink($temporary);
                throw new RuntimeException('Impossible de mettre à jour la sauvegarde automatique.');
            }

            return $target;
        } catch (Throwable $error) {
            try {
                report($error);
            } catch (Throwable) {
            }

            return null;
        }
    }

    /** @return array{name:string,created_at:string,size_bytes:int}|null */
    public function automaticBackup(): ?array
    {
        $path = $this->automaticBackupPath(false);
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        return [
            'name' => basename($path),
            'created_at' => date('d/m/Y H:i:s', filemtime($path) ?: 0),
            'size_bytes' => (int) (filesize($path) ?: 0),
        ];
    }

    public function automaticBackupPath(bool $mustExist = true): string
    {
        $path = (string) config('database.automatic_backup_path');
        if ($path === '') {
            throw new RuntimeException('Chemin de sauvegarde automatique non configuré.');
        }
        if (!str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $path = base_path($path);
        }
        if ($mustExist && (!is_file($path) || !is_readable($path))) {
            throw new RuntimeException('Sauvegarde automatique introuvable.');
        }

        return $path;
    }

    private function createSnapshot(string $path): string
    {
        $this->assertSqliteConnection();
        $pdo = DB::connection($this->connectionName())->getPdo();
        $quotedPath = $pdo->quote($path);

        if ($quotedPath === false) {
            throw new RuntimeException('Impossible de préparer le chemin de sauvegarde SQLite.');
        }

        DB::connection($this->connectionName())->statement('VACUUM INTO '.$quotedPath);
        $this->validateDatabaseFile($path);

        return $path;
    }

    /** @return array<int,array{name:string,created_at:string,size_bytes:int}> */
    public function backups(): array
    {
        $directory = $this->backupDirectory();
        File::ensureDirectoryExists($directory);

        $backups = [];
        foreach (glob($directory.'/yohan-compta-*.sqlite') ?: [] as $path) {
            $name = basename($path);
            if (!preg_match(self::BACKUP_FILENAME_PATTERN, $name) || !is_file($path)) {
                continue;
            }

            $createdAt = filemtime($path) ?: 0;
            $backups[] = [
                'name' => $name,
                'created_at' => date('d/m/Y H:i:s', $createdAt),
                'size_bytes' => (int) (filesize($path) ?: 0),
                '_created_at' => $createdAt,
            ];
        }

        usort($backups, static fn (array $left, array $right): int => $right['_created_at'] <=> $left['_created_at']);

        return array_map(static function (array $backup): array {
            unset($backup['_created_at']);

            return $backup;
        }, $backups);
    }

    public function backupPath(string $filename): string
    {
        if ($filename !== basename($filename) || !preg_match(self::BACKUP_FILENAME_PATTERN, $filename)) {
            throw new RuntimeException('Sauvegarde introuvable.');
        }

        $path = $this->backupDirectory().DIRECTORY_SEPARATOR.$filename;
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Sauvegarde introuvable.');
        }

        return $path;
    }

    public function restoreBackup(string $filename): void
    {
        $this->restoreDatabase($this->backupPath($filename), false);
    }

    public function deleteBackup(string $filename): void
    {
        $path = $this->backupPath($filename);
        if (!File::delete($path)) {
            throw new RuntimeException('Impossible de supprimer cette sauvegarde.');
        }
    }

    public function restoreFrom(string $sourcePath): string
    {
        $backup = $this->restoreDatabase($sourcePath, true);
        if ($backup === null) {
            throw new RuntimeException('La sauvegarde de sécurité avant restauration n’a pas pu être créée.');
        }

        return $backup;
    }

    private function restoreDatabase(string $sourcePath, bool $createSafetyBackup): ?string
    {
        $this->validateDatabaseFile($sourcePath);
        $safetyBackup = $createSafetyBackup ? $this->createBackup() : null;
        $databasePath = $this->databasePath();
        $replacementPath = $databasePath.'.restore-'.bin2hex(random_bytes(4));
        $rollbackPath = $databasePath.'.rollback-'.bin2hex(random_bytes(4));

        if (!copy($sourcePath, $replacementPath)) {
            throw new RuntimeException('Impossible de préparer la base SQLite à restaurer.');
        }

        $this->validateDatabaseFile($replacementPath);
        $originalMoved = false;

        try {
            $this->disconnectDatabase();
            $this->removeSidecars($databasePath);

            if (!rename($databasePath, $rollbackPath)) {
                throw new RuntimeException('Impossible de mettre la base actuelle en sécurité avant restauration.');
            }
            $originalMoved = true;

            if (!rename($replacementPath, $databasePath)) {
                throw new RuntimeException('Impossible d’installer la base SQLite restaurée.');
            }

            DB::purge($this->connectionName());
            DB::connection($this->connectionName())->getPdo();
            $this->assertLiveIntegrity();
            Artisan::call('migrate', ['--force' => true]);
            $this->assertLiveIntegrity();

            @unlink($rollbackPath);
        } catch (Throwable $error) {
            DB::purge($this->connectionName());
            $this->removeSidecars($databasePath);

            if ($originalMoved) {
                @unlink($databasePath);
                if (is_file($rollbackPath)) {
                    @rename($rollbackPath, $databasePath);
                }
            }
            @unlink($replacementPath);
            DB::purge($this->connectionName());

            throw new RuntimeException(
                $createSafetyBackup
                    ? 'La restauration a échoué. La base précédente a été conservée et sa sauvegarde de sécurité est disponible.'
                    : 'La restauration a échoué. La base précédente a été conservée.',
                0,
                $error,
            );
        }

        return $safetyBackup;
    }

    public function resetAll(): string
    {
        $backup = $this->createBackup();

        DB::transaction(function (): void {
            DB::table('overtime_payments')->delete();
            DB::table('work_days')->delete();
            DB::table('setting_periods')->delete();
            DB::table('setting_periods')->insert([
                'effective_from' => '2000-01-01',
                'default_start_time_minutes' => 465,
                'hourly_gross_rate_cents' => 1231,
                'hourly_net_rate_cents' => 974,
                'weekly_threshold_minutes' => 2100,
                'meal_allowance_cents' => 1600,
                'meal_allowance_time_minutes' => 855,
            ]);
        });

        return $backup;
    }

    public function resetMonth(int $year, int $month): string
    {
        if ($year < 2000 || $year > 2200 || $month < 1 || $month > 12) {
            throw new RuntimeException('Mois de réinitialisation invalide.');
        }

        $backup = $this->createBackup();
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        DB::transaction(function () use ($start, $end): void {
            DB::table('work_days')->whereBetween('date', [$start, $end])->delete();
            DB::table('overtime_payments')->whereBetween('payment_date', [$start, $end])->delete();
        });

        return $backup;
    }

    public function validateDatabaseFile(string $path): void
    {
        $this->assertSqliteConnection();

        if (!is_file($path) || !is_readable($path) || filesize($path) < 16) {
            throw new RuntimeException('Le fichier fourni n’est pas une base SQLite lisible.');
        }

        $handle = fopen($path, 'rb');
        $header = $handle ? fread($handle, 16) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }
        if ($header !== "SQLite format 3\0") {
            throw new RuntimeException('Le fichier fourni n’est pas une base SQLite valide.');
        }

        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            if ($pdo->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
                throw new RuntimeException('La vérification d’intégrité de la base SQLite a échoué.');
            }
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $error) {
            if ($error instanceof RuntimeException) {
                throw $error;
            }
            throw new RuntimeException('Impossible de lire la base SQLite fournie.', 0, $error);
        }

        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                throw new RuntimeException('La base SQLite fournie ne contient pas la structure attendue (table '.$table.' manquante).');
            }
        }
    }

    private function assertLiveIntegrity(): void
    {
        $connection = DB::connection($this->connectionName());
        if ($connection->selectOne('PRAGMA integrity_check')->integrity_check !== 'ok') {
            throw new RuntimeException('La base SQLite restaurée est corrompue.');
        }

        $tables = array_map(
            static fn (object $row): string => (string) $row->name,
            $connection->select("SELECT name FROM sqlite_master WHERE type = 'table'"),
        );
        foreach (self::REQUIRED_TABLES as $table) {
            if (!in_array($table, $tables, true)) {
                throw new RuntimeException('La base restaurée ne contient pas la table '.$table.'.');
            }
        }
    }

    private function disconnectDatabase(): void
    {
        try {
            DB::connection($this->connectionName())->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Throwable) {
            // A checkpoint is best effort; purging the PDO connection is the required step.
        }
        DB::purge($this->connectionName());
    }

    private function removeSidecars(string $databasePath): void
    {
        @unlink($databasePath.'-wal');
        @unlink($databasePath.'-shm');
    }

    private function databasePath(): string
    {
        $this->assertSqliteConnection();
        $database = (string) config('database.connections.'.$this->connectionName().'.database');
        if ($database === ':memory:' || $database === '') {
            throw new RuntimeException('La restauration nécessite une base SQLite stockée dans un fichier.');
        }

        if (!str_starts_with($database, DIRECTORY_SEPARATOR)) {
            $database = base_path($database);
        }

        if (!is_file($database)) {
            throw new RuntimeException('Le fichier de base SQLite actuel est introuvable.');
        }

        return $database;
    }

    private function backupDirectory(): string
    {
        return storage_path(self::BACKUP_DIRECTORY);
    }

    private function assertSqliteConnection(): void
    {
        if (config('database.connections.'.$this->connectionName().'.driver') !== 'sqlite') {
            throw new RuntimeException('Cette fonctionnalité est disponible uniquement avec SQLite.');
        }
    }

    private function connectionName(): string
    {
        return (string) config('database.default');
    }
}
