<?php

namespace App\Http\Controllers;

use App\Services\DatabaseMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DatabaseController
{
    public function backup(DatabaseMaintenanceService $database): BinaryFileResponse
    {
        $path = $database->createDownloadCopy();

        return response()
            ->download($path, 'yohan-compta-sauvegarde-'.now()->format('Y-m-d-His').'.sqlite')
            ->deleteFileAfterSend(true);
    }

    public function downloadBackup(string $backup, DatabaseMaintenanceService $database): BinaryFileResponse
    {
        try {
            $path = $database->backupPath($backup);
        } catch (RuntimeException) {
            abort(404);
        }

        return response()->download($path, $backup);
    }

    public function restoreBackup(Request $request, string $backup, DatabaseMaintenanceService $database): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        try {
            $database->restoreBackup($backup);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with(
            'status',
            'Sauvegarde restaurée : '.$backup.'.',
        );
    }

    public function deleteBackup(Request $request, string $backup, DatabaseMaintenanceService $database): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        try {
            $database->deleteBackup($backup);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with('status', 'Sauvegarde supprimée : '.$backup.'.');
    }

    public function deleteAllBackups(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        try {
            $deleted = $database->deleteAllBackups();
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with('status', $deleted.' sauvegarde(s) de sécurité supprimée(s).');
    }

    public function restore(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $data = $request->validate([
            'database_file' => ['required', 'file', 'max:51200'],
            'confirmed' => ['accepted'],
        ]);

        try {
            $backup = $database->restoreFrom($data['database_file']->getRealPath());
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['database_file' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with(
            'status',
            'Base restaurée. Sauvegarde de sécurité de l’état précédent : '.basename($backup).'.',
        );
    }
}
