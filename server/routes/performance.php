<?php

use App\Http\Controllers\Performance\AppraisalReviewController;
use App\Http\Controllers\Performance\CalibrationController;
use App\Http\Controllers\Performance\GoalController;
use App\Http\Controllers\Performance\MyGoalsController;
use App\Http\Controllers\Performance\MyPerformanceController;
use App\Http\Controllers\Performance\MyReviewsController;
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
| launching a cycle, scoring, submitting and recording sign-off need
| `performance.manage`.
|
| Taking part (`performance.participate`, ADR 0072): one's own appraisals, read
| and acknowledged; the reviews one is asked to write; one's own goals and their
| check-ins (ADR 0073). Goals and calibration sessions are their own sections.
| Every literal prefix is declared before the {evaluation} wildcard.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('performance')
    ->name('performance.')
    ->group(function () {
        // `performance.view`, or `performance.participate` (redirected to My appraisals).
        Route::get('/', [PerformanceController::class, 'index'])->name('index');
        Route::get('export', PerformanceExportController::class)->middleware('can:performance.view')->name('export');
        Route::post('/', [PerformanceEvaluationController::class, 'store'])->middleware('can:performance.manage')->name('store');

        // Open every appraisal of a cycle at once (idempotent — see the controller).
        Route::post('cycles', [PerformanceCycleController::class, 'store'])->middleware('can:performance.manage')->name('cycles.store');

        // My appraisals and my goals. An appraisal or goal is reachable here only
        // by its own employee (anyone else gets a 404).
        Route::middleware('can:performance.participate')->prefix('me')->name('me')->group(function () {
            Route::get('/', [MyPerformanceController::class, 'index']);
            Route::get('goals', [MyGoalsController::class, 'index'])->name('.goals');
            Route::post('goals', [MyGoalsController::class, 'store'])->name('.goals.store');
            Route::post('goals/{goal}/check-ins', [MyGoalsController::class, 'checkIn'])->name('.goals.check-in');
            Route::delete('goals/{goal}', [MyGoalsController::class, 'destroy'])->name('.goals.destroy');
            Route::get('{evaluation}', [MyPerformanceController::class, 'show'])->name('.show');
            Route::post('{evaluation}/acknowledge', [MyPerformanceController::class, 'acknowledge'])->name('.acknowledge');
        });

        // Reviews asked of the signed-in person — reachable only by the reviewer.
        Route::middleware('can:performance.participate')->prefix('reviews')->name('reviews.')->group(function () {
            Route::get('/', [MyReviewsController::class, 'index'])->name('index');
            Route::get('{review}', [MyReviewsController::class, 'show'])->name('show');
            Route::patch('{review}', [MyReviewsController::class, 'update'])->name('update');
            Route::post('{review}/submit', [MyReviewsController::class, 'submit'])->name('submit');
            Route::post('{review}/decline', [MyReviewsController::class, 'decline'])->name('decline');
        });

        // HR's side of a review request.
        Route::post('reviews/{review}/cancel', [AppraisalReviewController::class, 'cancel'])->middleware('can:performance.manage')->name('reviews.cancel');
        Route::post('reviews/{review}/remind', [AppraisalReviewController::class, 'remind'])->middleware('can:performance.manage')->name('reviews.remind');

        // Goals of a cycle (ADR 0073).
        Route::prefix('goals')->name('goals.')->group(function () {
            Route::get('/', [GoalController::class, 'index'])->middleware('can:performance.view')->name('index');
            Route::post('/', [GoalController::class, 'store'])->middleware('can:performance.manage')->name('store');
            Route::patch('{goal}', [GoalController::class, 'update'])->middleware('can:performance.manage')->name('update');
            Route::post('{goal}/check-ins', [GoalController::class, 'checkIn'])->middleware('can:performance.manage')->name('check-in');
            Route::post('{goal}/status', [GoalController::class, 'status'])->middleware('can:performance.manage')->name('status');
            Route::delete('{goal}', [GoalController::class, 'destroy'])->middleware('can:performance.manage')->name('destroy');
        });

        // Calibration sessions (ADR 0073).
        Route::prefix('calibration')->name('calibration.')->group(function () {
            Route::get('/', [CalibrationController::class, 'index'])->middleware('can:performance.view')->name('index');
            Route::post('/', [CalibrationController::class, 'store'])->middleware('can:performance.manage')->name('store');
            Route::get('{session}', [CalibrationController::class, 'show'])->middleware('can:performance.view')->name('show');
            Route::patch('{session}', [CalibrationController::class, 'update'])->middleware('can:performance.manage')->name('update');
            Route::post('{session}/adjustments', [CalibrationController::class, 'adjust'])->middleware('can:performance.manage')->name('adjust');
            Route::post('{session}/complete', [CalibrationController::class, 'complete'])->middleware('can:performance.manage')->name('complete');
            Route::post('{session}/cancel', [CalibrationController::class, 'cancel'])->middleware('can:performance.manage')->name('cancel');
        });

        // A single appraisal and its scorecard.
        Route::get('{evaluation}', [PerformanceController::class, 'show'])->middleware('can:performance.view')->name('show');
        Route::post('{evaluation}/insights', [PerformanceController::class, 'insights'])->middleware('can:performance.view')->name('insights');
        Route::post('{evaluation}/reviews', [AppraisalReviewController::class, 'store'])->middleware('can:performance.manage')->name('reviews.store');
        Route::patch('{evaluation}', [PerformanceEvaluationController::class, 'update'])->middleware('can:performance.manage')->name('update');
        Route::post('{evaluation}/submit', [PerformanceEvaluationController::class, 'submit'])->middleware('can:performance.manage')->name('submit');
        Route::post('{evaluation}/acknowledge', [PerformanceEvaluationController::class, 'acknowledge'])->middleware('can:performance.manage')->name('acknowledge');
        Route::delete('{evaluation}', [PerformanceEvaluationController::class, 'destroy'])->middleware('can:performance.manage')->name('destroy');
    });
