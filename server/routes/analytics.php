<?php

use App\Http\Controllers\Analytics\AttritionRiskController;
use App\Http\Controllers\Analytics\AttritionRiskRunController;
use App\Http\Controllers\Analytics\PerformanceForecastController;
use App\Http\Controllers\Analytics\PerformanceForecastRunController;
use App\Http\Controllers\Analytics\PromotionReadinessController;
use App\Http\Controllers\Analytics\PromotionReadinessRunController;
use Illuminate\Support\Facades\Route;

/*
| Predictive Workforce Analytics — surfaces powered by the ML inference service.
| Promotion Readiness scores every active employee for advancement; Performance
| Forecast projects each one's next-period rating; Attrition Risk scores each
| one's likelihood of resigning. Runs are addressed by hashid. Viewing needs the
| surface's `*.view` permission; triggering or deleting a run needs its `*.manage`
| permission. See docs/decisions/0043-attrition-risk-trained-on-the-attrition-surveys.md.
|
| All three surfaces embed their own model-graduation panel, which is
| frontend-only. See docs/decisions/0031-model-graduation-frontend-only.md.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('analytics')
    ->name('analytics.')
    ->group(function () {
        Route::prefix('attrition')
            ->name('attrition.')
            ->group(function () {
                Route::get('/', [AttritionRiskController::class, 'index'])->middleware('can:analytics.attrition.view')->name('index');
                Route::post('/', [AttritionRiskRunController::class, 'store'])->middleware('can:analytics.attrition.manage')->name('store');
                Route::delete('{run}', [AttritionRiskRunController::class, 'destroy'])->middleware('can:analytics.attrition.manage')->name('destroy');
            });

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
