<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => ['driver' => 'local', 'root' => env('LOCAL_FILESYSTEM_ROOT', storage_path('app/private')), 'throw' => false],
    ],
];
