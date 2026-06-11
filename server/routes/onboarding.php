<?php

use App\Http\Controllers\Onboarding\OnboardingCaseController;
use App\Http\Controllers\Onboarding\OnboardingProgramController;
use App\Http\Controllers\Onboarding\OnboardingTaskController;
use Illuminate\Support\Facades\Route;

/*
| The Onboarding module — the bridge between a hire and a productive employee.
| Reusable programs (templates) seed a per-employee case of checklist tasks
| (usually at hire time, via the recruitment bridge). Every route is
| permission-gated. Literal-prefixed routes are declared before the `{case}`
| wildcard so they resolve correctly.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('onboarding')
    ->name('onboarding.')
    ->group(function () {
        Route::get('/', [OnboardingCaseController::class, 'index'])->middleware('can:onboarding.view')->name('index');
        Route::post('/', [OnboardingCaseController::class, 'store'])->middleware('can:onboarding.manage')->name('store');

        // Programs (templates).
        Route::get('programs', [OnboardingProgramController::class, 'index'])->middleware('can:onboarding.manage-programs')->name('programs.index');
        Route::post('programs', [OnboardingProgramController::class, 'store'])->middleware('can:onboarding.manage-programs')->name('programs.store');
        Route::post('programs/{program}', [OnboardingProgramController::class, 'update'])->middleware('can:onboarding.manage-programs')->name('programs.update');
        Route::delete('programs/{program}', [OnboardingProgramController::class, 'destroy'])->middleware('can:onboarding.manage-programs')->name('programs.destroy');

        // Checklist tasks (addressed by numeric id, like recruitment sub-resources).
        Route::post('tasks/{task}', [OnboardingTaskController::class, 'update'])->middleware('can:onboarding.manage')->name('tasks.update');
        Route::patch('tasks/{task}/status', [OnboardingTaskController::class, 'toggle'])->middleware('can:onboarding.manage')->name('tasks.status');
        Route::delete('tasks/{task}', [OnboardingTaskController::class, 'destroy'])->middleware('can:onboarding.manage')->name('tasks.destroy');

        // A case and its checklist (wildcard — declared last).
        Route::get('{case}', [OnboardingCaseController::class, 'show'])->middleware('can:onboarding.view')->name('show');
        Route::post('{case}', [OnboardingCaseController::class, 'update'])->middleware('can:onboarding.manage')->name('update');
        Route::patch('{case}/status', [OnboardingCaseController::class, 'status'])->middleware('can:onboarding.manage')->name('status');
        Route::delete('{case}', [OnboardingCaseController::class, 'destroy'])->middleware('can:onboarding.manage')->name('destroy');
        Route::post('{case}/tasks', [OnboardingTaskController::class, 'store'])->middleware('can:onboarding.manage')->name('tasks.store');
    });
