<?php

use App\Http\Controllers\CheckIns\CheckInController;
use App\Http\Controllers\CheckIns\CheckInResultsController;
use Illuminate\Support\Facades\Route;

/*
| Mood check-ins (ADR 0102) — once a month, each employee says how work is going.
| Answering one's own needs `check-ins.respond`; the team results (groups of five
| or more only) need `check-ins.view`; turning check-ins on or off needs
| `check-ins.manage`. The landing page sends HR to the results and everyone else to
| their own check-in. Nobody's single answers are ever served to anybody else.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('check-ins')
    ->name('check-ins.')
    ->group(function () {
        Route::get('/', [CheckInController::class, 'index'])->name('index');

        Route::middleware('can:check-ins.respond')->group(function () {
            Route::get('me', [CheckInController::class, 'me'])->name('me');
            Route::post('me', [CheckInController::class, 'store'])->middleware('throttle:20,1')->name('store');
        });

        Route::get('results', [CheckInResultsController::class, 'index'])->middleware('can:check-ins.view')->name('results');
        Route::post('settings', [CheckInResultsController::class, 'settings'])->middleware('can:check-ins.manage')->name('settings');
    });
