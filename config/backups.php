<?php

return [
    'directory' => env('BACKUP_DIRECTORY', 'app/private/backups'),
    'retention' => max(1, (int) env('BACKUP_RETENTION', 20)),
];
