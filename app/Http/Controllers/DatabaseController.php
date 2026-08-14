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
        $path = $database->createBackup();

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
            $safetyBackup = $database->restoreBackup($backup);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['backup' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with(
            'status',
            'Sauvegarde restaurée : '.$backup.'. Une sauvegarde de sécurité de l’état précédent a été créée : '.basename($safetyBackup).'.',
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
            'Base restaurée. Sauvegarde automatique de l’état précédent : '.basename($backup).'.',
        );
    }

    public function resetAll(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $request->validate(['confirmed' => ['accepted']]);

        try {
            $backup = $database->resetAll();
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['database' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with(
            'status',
            'Site réinitialisé. Une sauvegarde de sécurité a été créée : '.basename($backup).'.',
        );
    }

    public function resetMonth(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2200'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'confirmed' => ['accepted'],
        ]);

        try {
            $backup = $database->resetMonth((int) $data['year'], (int) $data['month']);
        } catch (RuntimeException $error) {
            throw ValidationException::withMessages(['database' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with(
            'status',
            sprintf(
                'Données de %02d/%04d supprimées. Sauvegarde de sécurité : %s.',
                $data['month'],
                $data['year'],
                basename($backup),
            ),
        );
    }
}
