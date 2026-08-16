<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

final class DatabaseAccessLock
{
    public function shared(callable $callback): mixed
    {
        return $this->run(LOCK_SH, $callback);
    }

    public function exclusive(callable $callback): mixed
    {
        return $this->run(LOCK_EX, $callback);
    }

    private function run(int $mode, callable $callback): mixed
    {
        $path = storage_path('app/private/database-access.lock');
        File::ensureDirectoryExists(dirname($path));
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException('Impossible d’ouvrir le verrou d’accès à la base de données.');
        }

        try {
            if (!flock($handle, $mode)) {
                throw new RuntimeException('Impossible de verrouiller temporairement l’accès à la base de données.');
            }

            try {
                return $callback();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
