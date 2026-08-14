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

    public function createBackup(): string
    {
        $this->assertSqliteConnection();
        $directory = storage_path('app/private/backups');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/yohan-compta-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.sqlite';
        $pdo = DB::connection($this->connectionName())->getPdo();
        $quotedPath = $pdo->quote($path);

        if ($quotedPath === false) {
            throw new RuntimeException('Impossible de préparer le chemin de sauvegarde SQLite.');
        }

        DB::connection($this->connectionName())->statement('VACUUM INTO '.$quotedPath);
        $this->validateDatabaseFile($path);

        return $path;
    }

    public function restoreFrom(string $sourcePath): string
    {
        $this->validateDatabaseFile($sourcePath);
        $safetyBackup = $this->createBackup();
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
                'La restauration a échoué. La base précédente a été conservée et sa sauvegarde automatique est disponible.',
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
