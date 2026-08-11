<?php

use App\Http\Controllers\Performance\PerformanceController;
use App\Http\Controllers\Performance\PerformanceCycleController;
use App\Http\Controllers\Performance\PerformanceEvaluationController;
use App\Http\Controllers\Performance\PerformanceExportController;
use Illuminate\Support\Facades\Route;

/*
| Performance Management — the live appraisal program: a cycle-scoped overview
| (coverage, band distribution, per-department calibration) and the scorecard for
| one appraisal, conducted against an appraisal framework (ADR 0028).
| Evaluations are addressed by hashid. Viewing needs `performance.view`; opening,
| launching a cycle, scoring, submitting and acknowledging need
| `performance.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('performance')
    ->name('performance.')
    ->group(function () {
        Route::get('/', [PerformanceController::class, 'index'])->middleware('can:performance.view')->name('index');
        Route::get('export', PerformanceExportController::class)->middleware('can:performance.view')->name('export');
        Route::post('/', [PerformanceEvaluationController::class, 'store'])->middleware('can:performance.manage')->name('store');

        // Open every appraisal of a cycle at once (idempotent — see the controller).
        Route::post('cycles', [PerformanceCycleController::class, 'store'])->middleware('can:performance.manage')->name('cycles.store');

        // A single appraisal and its scorecard.
        Route::get('{evaluation}', [PerformanceController::class, 'show'])->middleware('can:performance.view')->name('show');
        Route::post('{evaluation}/insights', [PerformanceController::class, 'insights'])->middleware('can:performance.view')->name('insights');
        Route::patch('{evaluation}', [PerformanceEvaluationController::class, 'update'])->middleware('can:performance.manage')->name('update');
        Route::post('{evaluation}/submit', [PerformanceEvaluationController::class, 'submit'])->middleware('can:performance.manage')->name('submit');
        Route::post('{evaluation}/acknowledge', [PerformanceEvaluationController::class, 'acknowledge'])->middleware('can:performance.manage')->name('acknowledge');
        Route::delete('{evaluation}', [PerformanceEvaluationController::class, 'destroy'])->middleware('can:performance.manage')->name('destroy');
    });
