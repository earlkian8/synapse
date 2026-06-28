<?php

use App\Http\Controllers\Report\ReportController;
use Illuminate\Support\Facades\Route;

/*
 * Reports — audit-oriented, parameterised views over the active organisation's data.
 * The hub is open to any authenticated user; each report re-authorises against its own
 * permission (see ReportController), so the catalogue only shows what the user may run.
 */
Route::middleware(['auth', 'verified'])
    ->prefix('reports')
    ->name('reports.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{report}/export', [ReportController::class, 'export'])->name('export');
        Route::get('{report}', [ReportController::class, 'show'])->name('show');
    });
