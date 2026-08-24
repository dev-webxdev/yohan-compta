<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\MonthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SalaryController;
use App\Http\Controllers\WorkDayController;
use App\Http\Controllers\YearController;
use Illuminate\Support\Facades\Route;

Route::get('/connexion', [AuthController::class, 'create'])->name('login');
Route::post('/connexion', [AuthController::class, 'store'])->name('login.store');

Route::middleware('auth.local')->group(function (): void {
    Route::get('/', fn () => redirect()->route('month.current'));
    Route::post('/deconnexion', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/mois', MonthController::class)->name('month.current');
    Route::get('/mois/{month}', MonthController::class)->where('month', '\\d{4}-\\d{2}')->name('month.show');
    Route::get('/mois/{month}/export.csv', [ExportController::class, 'month'])->where('month', '\\d{4}-\\d{2}')->name('month.export');
    Route::get('/jours/{date}', [WorkDayController::class, 'show'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('days.show');
    Route::put('/jours/{date}', [WorkDayController::class, 'store'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('days.store');
    Route::post('/jours/{date}/copier-veille', [WorkDayController::class, 'copyPreviousDay'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('days.copy-previous');

    Route::get('/paiements', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('/paiements', [PaymentController::class, 'store'])->name('payments.store');
    Route::patch('/paiements/{payment}', [PaymentController::class, 'update'])->name('payments.update');
    Route::delete('/paiements/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    Route::get('/salaires', [SalaryController::class, 'index'])->name('salaries.index');
    Route::post('/salaires', [SalaryController::class, 'store'])->name('salaries.store');
    Route::patch('/salaires/{salary}', [SalaryController::class, 'update'])->name('salaries.update');
    Route::delete('/salaires/{salary}', [SalaryController::class, 'destroy'])->name('salaries.destroy');

    Route::get('/annee/{year?}', YearController::class)->where('year', '\\d{4}')->name('year.show');
    Route::get('/annee/{year}/export.csv', [ExportController::class, 'year'])->where('year', '\\d{4}')->name('year.export');

    Route::get('/parametres', [SettingsController::class, 'index'])->name('settings.index');
    Route::get('/parametres/valeurs', [SettingsController::class, 'values'])->name('settings.values');
    Route::post('/parametres', [SettingsController::class, 'store'])->name('settings.store');
    Route::get('/parametres/base/sauvegarde', [DatabaseController::class, 'backup'])->name('settings.database.backup');
    Route::post('/parametres/base/restauration', [DatabaseController::class, 'restore'])->name('settings.database.restore');
});
