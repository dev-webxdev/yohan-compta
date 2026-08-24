<?php

namespace App\Services;

use App\Support\DateRange;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseMaintenanceService
{
    private const REQUIRED_TABLES = ['migrations', 'work_days', 'setting_periods', 'overtime_payments'];
    private const BASELINE_COLUMNS = [
        'migrations' => ['id', 'migration', 'batch'],
        'work_days' => ['id', 'date', 'driving_minutes', 'warehouse_minutes', 'meal_allowance_mode', 'meal_allowance_forced_cents'],
        'setting_periods' => ['id', 'effective_from', 'weekly_threshold_minutes', 'meal_allowance_cents', 'meal_allowance_time_minutes'],
        'overtime_payments' => ['id', 'payment_date', 'amount_cents'],
    ];
    private const CURRENT_COLUMNS = [
        'migrations' => ['id', 'migration', 'batch'],
        'work_days' => [
            'id', 'date', 'start_time_minutes', 'driving_minutes', 'warehouse_minutes', 'is_rest',
            'meal_allowance_mode', 'meal_allowance_forced_cents', 'client_write_version', 'created_at', 'updated_at',
        ],
        'setting_periods' => [
            'id', 'effective_from', 'default_start_time_minutes', 'hourly_net_rate_cents',
            'weekly_threshold_minutes', 'meal_allowance_cents', 'meal_allowance_time_minutes',
        ],
        'overtime_payments' => [
            'id', 'payment_date', 'amount_cents', 'hours_paid_minutes', 'period_reference', 'created_at', 'updated_at',
        ],
        'monthly_salaries' => ['id', 'month', 'net_amount_cents', 'note', 'created_at', 'updated_at'],
    ];

    public function __construct(private readonly DatabaseAccessLock $databaseLock)
    {
    }

    public function createDownloadCopy(): string
    {
        $this->assertSqliteConnection();
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'yohan-compta-download-'.bin2hex(random_bytes(6)).'.sqlite';
        $pdo = DB::connection($this->connectionName())->getPdo();
        $quotedPath = $pdo->quote($path);

        if ($quotedPath === false) {
            throw new RuntimeException('Impossible de préparer le chemin de sauvegarde SQLite.');
        }

        DB::connection($this->connectionName())->statement('VACUUM INTO '.$quotedPath);
        $this->validateDatabaseFile($path);

        return $path;
    }

    public function restoreFrom(string $sourcePath): void
    {
        $this->databaseLock->exclusive(fn () => $this->restoreDatabaseLocked($sourcePath));
    }

    private function restoreDatabaseLocked(string $sourcePath): void
    {
        $this->validateDatabaseFile($sourcePath);
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
            $this->assertLiveSchema();

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
                'La restauration a échoué. La base précédente a été conservée.',
                0,
                $error,
            );
        }
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

        $this->assertBaselineSchema($pdo);
        $this->assertKnownMigrations($pdo);
        $this->assertSettingsCoverage($pdo);
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

    private function assertBaselineSchema(PDO $pdo): void
    {
        foreach (self::BASELINE_COLUMNS as $table => $requiredColumns) {
            $this->assertPdoColumns($pdo, $table, $requiredColumns);
        }

        $settingsColumns = $this->pdoColumns($pdo, 'setting_periods');
        if (!array_intersect(['hourly_rate_cents', 'hourly_gross_rate_cents', 'hourly_net_rate_cents'], $settingsColumns)) {
            throw new RuntimeException('La base SQLite fournie ne contient aucun taux horaire compatible.');
        }
    }

    private function assertKnownMigrations(PDO $pdo): void
    {
        $known = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $path) {
            $known[] = pathinfo($path, PATHINFO_FILENAME);
        }

        $stored = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $unknown = array_values(array_diff($stored, $known));
        if ($unknown !== []) {
            throw new RuntimeException('Cette sauvegarde provient d’une version plus récente ou incompatible de l’application.');
        }
    }

    private function assertSettingsCoverage(PDO $pdo): void
    {
        $first = $pdo->query('SELECT MIN(effective_from) FROM setting_periods')->fetchColumn();
        if (!is_string($first) || $first === '' || $first > DateRange::MIN_DATE) {
            throw new RuntimeException('La base SQLite fournie ne contient pas de période de paramètres couvrant les données historiques.');
        }
    }

    private function assertLiveSchema(): void
    {
        $connection = DB::connection($this->connectionName());
        foreach (self::CURRENT_COLUMNS as $table => $requiredColumns) {
            $rows = $connection->select('PRAGMA table_info("'.$table.'")');
            $columns = array_map(static fn (object $row): string => (string) $row->name, $rows);
            $missing = array_values(array_diff($requiredColumns, $columns));
            if ($missing !== []) {
                throw new RuntimeException('La base restaurée reste incompatible après migration ('.$table.' : '.implode(', ', $missing).' manquant'.(count($missing) > 1 ? 's' : '').').');
            }
        }

        $firstSetting = $connection->table('setting_periods')->orderBy('effective_from')->first();
        if (!$firstSetting || (string) $firstSetting->effective_from > DateRange::MIN_DATE) {
            throw new RuntimeException('La base restaurée ne contient pas de paramètres historiques utilisables.');
        }

        $connection->table('work_days')->limit(1)->get();
        $connection->table('overtime_payments')->limit(1)->get();
        $connection->table('monthly_salaries')->limit(1)->get();
    }

    /** @param list<string> $requiredColumns */
    private function assertPdoColumns(PDO $pdo, string $table, array $requiredColumns): void
    {
        $columns = $this->pdoColumns($pdo, $table);
        $missing = array_values(array_diff($requiredColumns, $columns));
        if ($missing !== []) {
            throw new RuntimeException('La base SQLite fournie contient une structure incompatible ('.$table.' : '.implode(', ', $missing).' manquant'.(count($missing) > 1 ? 's' : '').').');
        }
    }

    /** @return list<string> */
    private function pdoColumns(PDO $pdo, string $table): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['name'],
            $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC),
        );
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
