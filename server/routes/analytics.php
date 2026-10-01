<?php

use App\Http\Controllers\Analytics\AttritionRiskController;
use App\Http\Controllers\Analytics\AttritionRiskRunController;
use App\Http\Controllers\Analytics\ModelGraduationController;
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
| All three surfaces embed their own model-graduation panel (ADR 0046): once the
| organisation's records meet a surface's requirements, `graduation` trains that
| surface's model on them and checks it, `graduation/{localModel}/activate`
| switches the surface to a model that passed, and `DELETE graduation` switches it
| back to the general model. Each needs the surface's `*.manage` permission. See
| docs/decisions/0046-model-graduation-trains-on-the-organisations-own-records.md.
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

                // Before `{run}`, which would otherwise read "graduation" as a run.
                Route::prefix('graduation')->name('graduation.')->middleware('can:analytics.attrition.manage')->group(function () {
                    Route::post('/', [ModelGraduationController::class, 'train'])->defaults('surface', 'attrition')->name('train');
                    Route::post('{localModel}/activate', [ModelGraduationController::class, 'activate'])->defaults('surface', 'attrition')->name('activate');
                    Route::delete('/', [ModelGraduationController::class, 'revert'])->defaults('surface', 'attrition')->name('revert');
                });

                Route::delete('{run}', [AttritionRiskRunController::class, 'destroy'])->middleware('can:analytics.attrition.manage')->name('destroy');
            });

        Route::prefix('promotion-readiness')
            ->name('promotion-readiness.')
            ->group(function () {
                Route::get('/', [PromotionReadinessController::class, 'index'])->middleware('can:analytics.promotion.view')->name('index');
                Route::post('/', [PromotionReadinessRunController::class, 'store'])->middleware('can:analytics.promotion.manage')->name('store');

                // Before `{run}`, which would otherwise read "graduation" as a run.
                Route::prefix('graduation')->name('graduation.')->middleware('can:analytics.promotion.manage')->group(function () {
                    Route::post('/', [ModelGraduationController::class, 'train'])->defaults('surface', 'promotion')->name('train');
                    Route::post('{localModel}/activate', [ModelGraduationController::class, 'activate'])->defaults('surface', 'promotion')->name('activate');
                    Route::delete('/', [ModelGraduationController::class, 'revert'])->defaults('surface', 'promotion')->name('revert');
                });

                Route::delete('{run}', [PromotionReadinessRunController::class, 'destroy'])->middleware('can:analytics.promotion.manage')->name('destroy');
            });

        Route::prefix('performance-forecast')
            ->name('performance-forecast.')
            ->group(function () {
                Route::get('/', [PerformanceForecastController::class, 'index'])->middleware('can:analytics.performance.view')->name('index');
                Route::post('/', [PerformanceForecastRunController::class, 'store'])->middleware('can:analytics.performance.manage')->name('store');

                // Before `{run}`, which would otherwise read "graduation" as a run.
                Route::prefix('graduation')->name('graduation.')->middleware('can:analytics.performance.manage')->group(function () {
                    Route::post('/', [ModelGraduationController::class, 'train'])->defaults('surface', 'performance')->name('train');
                    Route::post('{localModel}/activate', [ModelGraduationController::class, 'activate'])->defaults('surface', 'performance')->name('activate');
                    Route::delete('/', [ModelGraduationController::class, 'revert'])->defaults('surface', 'performance')->name('revert');
                });

                Route::delete('{run}', [PerformanceForecastRunController::class, 'destroy'])->middleware('can:analytics.performance.manage')->name('destroy');
            });
    });
