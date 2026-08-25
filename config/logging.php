<?php

return [
    'default' => env('LOG_CHANNEL', 'single'),
    'channels' => [
        'single' => ['driver' => 'single', 'path' => storage_path('logs/laravel.log'), 'level' => env('LOG_LEVEL', 'debug'), 'replace_placeholders' => true],
        'stderr' => ['driver' => 'single', 'path' => 'php://stderr', 'level' => env('LOG_LEVEL', 'warning'), 'replace_placeholders' => true],
    ],
];
