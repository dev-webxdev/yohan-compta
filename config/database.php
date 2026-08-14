<?php

use Illuminate\Support\Str;

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'automatic_backup_path' => env('DB_AUTOMATIC_BACKUP_PATH', storage_path('app/private/automatic/yohan-compta-automatique.sqlite')),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => true,
            'busy_timeout' => 5000,
            'journal_mode' => 'WAL',
            'synchronous' => 'NORMAL',
        ],
    ],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),
        'options' => ['cluster' => env('REDIS_CLUSTER', 'redis'), 'prefix' => Str::slug((string) env('APP_NAME', 'laravel')).'-database-'],
    ],
];
