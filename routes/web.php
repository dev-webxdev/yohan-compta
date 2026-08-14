<?php

use App\Http\Controllers\DatabaseController;
use App\Http\Controllers\MonthController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkDayController;
use App\Http\Controllers\YearController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('month.current'));
Route::get('/mois', MonthController::class)->name('month.current');
Route::get('/mois/{month}', MonthController::class)->where('month', '\\d{4}-\\d{2}')->name('month.show');
Route::put('/jours/{date}', [WorkDayController::class, 'store'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('days.store');
Route::delete('/jours/{date}', [WorkDayController::class, 'destroy'])->where('date', '\\d{4}-\\d{2}-\\d{2}')->name('days.destroy');
Route::get('/paiements', [PaymentController::class, 'index'])->name('payments.index');
Route::post('/paiements', [PaymentController::class, 'store'])->name('payments.store');
Route::delete('/paiements/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
Route::get('/annee/{year?}', YearController::class)->where('year', '\\d{4}')->name('year.show');
Route::get('/parametres', [SettingsController::class, 'index'])->name('settings.index');
Route::post('/parametres', [SettingsController::class, 'store'])->name('settings.store');
Route::get('/parametres/base/sauvegarde', [DatabaseController::class, 'backup'])->name('settings.database.backup');
Route::post('/parametres/base/restauration', [DatabaseController::class, 'restore'])->name('settings.database.restore');
Route::get('/parametres/base/sauvegardes/{backup}', [DatabaseController::class, 'downloadBackup'])->where('backup', 'yohan-compta-\\d{8}-\\d{6}-[a-f0-9]{6}\\.sqlite')->name('settings.database.backups.download');
Route::post('/parametres/base/sauvegardes/{backup}/restauration', [DatabaseController::class, 'restoreBackup'])->where('backup', 'yohan-compta-\\d{8}-\\d{6}-[a-f0-9]{6}\\.sqlite')->name('settings.database.backups.restore');
Route::delete('/parametres/base/sauvegardes/{backup}', [DatabaseController::class, 'deleteBackup'])->where('backup', 'yohan-compta-\\d{8}-\\d{6}-[a-f0-9]{6}\\.sqlite')->name('settings.database.backups.delete');
Route::delete('/parametres/base', [DatabaseController::class, 'resetAll'])->name('settings.database.reset');
Route::delete('/parametres/base/mois', [DatabaseController::class, 'resetMonth'])->name('settings.database.reset-month');
