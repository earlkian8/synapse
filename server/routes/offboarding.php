<?php

use App\Http\Controllers\Offboarding\ClearanceExportController;
use App\Http\Controllers\Offboarding\ClearanceItemController;
use App\Http\Controllers\Offboarding\OffboardingCaseController;
use App\Http\Controllers\Offboarding\OffboardingExportController;
use Illuminate\Support\Facades\Route;

/*
| The Offboarding module — the structured exit of a departing employee. An
| offboarding case seeds a clearance checklist (from a clearance template, or the
| built-in standard list) routed to the responsible departments; finalising it
| separates the employee. Templates are configured under Company Setup
| (setup.offboarding.*). Every route is permission-gated. Literal-prefixed routes
| are declared before the `{case}` wildcard so they resolve correctly.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('offboarding')
    ->name('offboarding.')
    ->group(function () {
        Route::get('/', [OffboardingCaseController::class, 'index'])->middleware('can:offboarding.view')->name('index');
        Route::post('/', [OffboardingCaseController::class, 'store'])->middleware('can:offboarding.manage')->name('store');

        // CSV export of the board, honouring the current filters (literal —
        // declared before the wildcard).
        Route::get('export', OffboardingExportController::class)->middleware('can:offboarding.view')->name('export');

        // Clearance items (addressed by numeric id, like recruitment sub-resources).
        Route::post('clearance/{item}', [ClearanceItemController::class, 'update'])->middleware('can:offboarding.manage')->name('clearance.update');
        Route::patch('clearance/{item}/status', [ClearanceItemController::class, 'toggle'])->middleware('can:offboarding.manage')->name('clearance.status');
        Route::delete('clearance/{item}', [ClearanceItemController::class, 'destroy'])->middleware('can:offboarding.manage')->name('clearance.destroy');

        // A case and its clearance checklist (wildcard — declared last).
        Route::get('{case}', [OffboardingCaseController::class, 'show'])->middleware('can:offboarding.view')->name('show');
        Route::get('{case}/export', ClearanceExportController::class)->middleware('can:offboarding.view')->name('clearance.export');
        Route::post('{case}', [OffboardingCaseController::class, 'update'])->middleware('can:offboarding.manage')->name('update');
        Route::patch('{case}/status', [OffboardingCaseController::class, 'status'])->middleware('can:offboarding.manage')->name('status');
        Route::delete('{case}', [OffboardingCaseController::class, 'destroy'])->middleware('can:offboarding.manage')->name('destroy');
        Route::post('{case}/clearance', [ClearanceItemController::class, 'store'])->middleware('can:offboarding.manage')->name('clearance.store');
        Route::post('{case}/clearance/apply-program', [ClearanceItemController::class, 'applyProgram'])->middleware('can:offboarding.manage')->name('clearance.apply-program');
        Route::patch('{case}/clearance/bulk-clear', [ClearanceItemController::class, 'bulkClear'])->middleware('can:offboarding.manage')->name('clearance.bulk-clear');
    });
