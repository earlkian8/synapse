<?php

use App\Http\Controllers\Analytics\PromotionReadinessController;
use App\Http\Controllers\Analytics\PromotionReadinessRunController;
use Illuminate\Support\Facades\Route;

/*
| Predictive Workforce Analytics — surfaces powered by the ML inference service.
| Promotion Readiness scores every active employee for advancement. Runs are
| addressed by hashid. Viewing needs `analytics.promotion.view`; triggering or
| deleting an assessment needs `analytics.promotion.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('analytics')
    ->name('analytics.')
    ->group(function () {
        Route::prefix('promotion-readiness')
            ->name('promotion-readiness.')
            ->group(function () {
                Route::get('/', [PromotionReadinessController::class, 'index'])->middleware('can:analytics.promotion.view')->name('index');
                Route::post('/', [PromotionReadinessRunController::class, 'store'])->middleware('can:analytics.promotion.manage')->name('store');
                Route::delete('{run}', [PromotionReadinessRunController::class, 'destroy'])->middleware('can:analytics.promotion.manage')->name('destroy');
            });
    });
