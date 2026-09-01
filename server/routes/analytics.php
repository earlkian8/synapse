<?php

use App\Http\Controllers\Analytics\PerformanceForecastController;
use App\Http\Controllers\Analytics\PerformanceForecastRunController;
use App\Http\Controllers\Analytics\PromotionReadinessController;
use App\Http\Controllers\Analytics\PromotionReadinessRunController;
use Illuminate\Support\Facades\Route;

/*
| Predictive Workforce Analytics — surfaces powered by the ML inference service.
| Promotion Readiness scores every active employee for advancement; Performance
| Forecast projects each one's next-period rating. Runs are addressed by hashid.
| Viewing needs the surface's `*.view` permission; triggering or deleting a run
| needs its `*.manage` permission.
|
| Attrition Risk is NOT one of these — it's a frontend-only demo surface (no
| controller, no persisted data, no permission) that generates its own
| illustrative scores client-side. See docs/decisions/0030-attrition-risk-frontend-only.md.
|
| All three surfaces embed their own model-graduation panel, which is likewise
| frontend-only. See docs/decisions/0031-model-graduation-frontend-only.md.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('analytics')
    ->name('analytics.')
    ->group(function () {
        Route::inertia('attrition', 'analytics/attrition')->name('attrition.index');

        Route::prefix('promotion-readiness')
            ->name('promotion-readiness.')
            ->group(function () {
                Route::get('/', [PromotionReadinessController::class, 'index'])->middleware('can:analytics.promotion.view')->name('index');
                Route::post('/', [PromotionReadinessRunController::class, 'store'])->middleware('can:analytics.promotion.manage')->name('store');
                Route::delete('{run}', [PromotionReadinessRunController::class, 'destroy'])->middleware('can:analytics.promotion.manage')->name('destroy');
            });

        Route::prefix('performance-forecast')
            ->name('performance-forecast.')
            ->group(function () {
                Route::get('/', [PerformanceForecastController::class, 'index'])->middleware('can:analytics.performance.view')->name('index');
                Route::post('/', [PerformanceForecastRunController::class, 'store'])->middleware('can:analytics.performance.manage')->name('store');
                Route::delete('{run}', [PerformanceForecastRunController::class, 'destroy'])->middleware('can:analytics.performance.manage')->name('destroy');
            });
    });
