<?php

use App\Http\Controllers\Performance\PerformanceController;
use App\Http\Controllers\Performance\PerformanceEvaluationController;
use Illuminate\Support\Facades\Route;

/*
| Performance Management — the live appraisal program: an overview of every
| evaluation with its score + status, and the KPI scorecard for one evaluation.
| Evaluations are addressed by hashid. Viewing needs `performance.view`; opening,
| scoring, submitting and acknowledging need `performance.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('performance')
    ->name('performance.')
    ->group(function () {
        Route::get('/', [PerformanceController::class, 'index'])->middleware('can:performance.view')->name('index');
        Route::post('/', [PerformanceEvaluationController::class, 'store'])->middleware('can:performance.manage')->name('store');

        // A single evaluation and its scorecard.
        Route::get('{evaluation}', [PerformanceController::class, 'show'])->middleware('can:performance.view')->name('show');
        Route::patch('{evaluation}', [PerformanceEvaluationController::class, 'update'])->middleware('can:performance.manage')->name('update');
        Route::post('{evaluation}/submit', [PerformanceEvaluationController::class, 'submit'])->middleware('can:performance.manage')->name('submit');
        Route::post('{evaluation}/acknowledge', [PerformanceEvaluationController::class, 'acknowledge'])->middleware('can:performance.manage')->name('acknowledge');
        Route::delete('{evaluation}', [PerformanceEvaluationController::class, 'destroy'])->middleware('can:performance.manage')->name('destroy');
    });
