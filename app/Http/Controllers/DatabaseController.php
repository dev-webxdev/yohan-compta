<?php

namespace App\Http\Controllers;

use App\Services\DatabaseMaintenanceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class DatabaseController
{
    public function backup(DatabaseMaintenanceService $database): BinaryFileResponse
    {
        $path = $database->createApplicationBackup();

        return response()
            ->download($path, 'yohan-compta-sauvegarde-'.now()->format('Y-m-d-His').'.zip')
            ->deleteFileAfterSend(true);
    }

    public function restore(Request $request, DatabaseMaintenanceService $database): RedirectResponse
    {
        $data = $request->validate([
            'database_file' => ['required', 'file', 'max:524288'],
            'confirmed' => ['accepted'],
        ]);

        try {
            $database->restoreFrom($data['database_file']->getRealPath());
        } catch (\RuntimeException $error) {
            throw ValidationException::withMessages(['database_file' => $error->getMessage()]);
        }

        return redirect()->route('settings.index')->with('status', 'Sauvegarde restaurée.');
    }
}
