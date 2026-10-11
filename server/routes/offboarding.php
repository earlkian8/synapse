<?php

use App\Http\Controllers\Offboarding\ClearanceExportController;
use App\Http\Controllers\Offboarding\ClearanceItemController;
use App\Http\Controllers\Offboarding\ExitInterviewController;
use App\Http\Controllers\Offboarding\MyOffboardingController;
use App\Http\Controllers\Offboarding\OffboardingCaseController;
use App\Http\Controllers\Offboarding\OffboardingDocumentController;
use App\Http\Controllers\Offboarding\OffboardingExportController;
use App\Http\Controllers\Offboarding\ResignationController;
use Illuminate\Support\Facades\Route;

/*
| The Offboarding module — the structured exit of a departing employee. An
| offboarding case seeds a clearance checklist (from a clearance template, or the
| built-in standard list) routed to the responsible departments; finalising it
| separates the employee. Templates are configured under Company Setup
| (setup.offboarding.*). Every route is permission-gated. Literal-prefixed routes
| are declared before the `{case}` wildcard so they resolve correctly.
|
| An employee files their own resignation, answers their exit interview and
| downloads their Certificate of Employment under `me` (ADRs 0076, 0077);
| HR reviews resignations and reads exit interviews in sections of their own.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('offboarding')
    ->name('offboarding.')
    ->group(function () {
        // `offboarding.view`, or `offboarding.resign` (redirected to My resignation).
        Route::get('/', [OffboardingCaseController::class, 'index'])->name('index');
        Route::post('/', [OffboardingCaseController::class, 'store'])->middleware('can:offboarding.manage')->name('store');

        // CSV export of the board, honouring the current filters (literal —
        // declared before the wildcard).
        Route::get('export', OffboardingExportController::class)->middleware('can:offboarding.view')->name('export');

        // Leaving, for the person who is (literal `me` — before the wildcard).
        Route::middleware('can:offboarding.resign')->group(function () {
            Route::get('me', [MyOffboardingController::class, 'index'])->name('me');
            Route::post('me/resignation', [MyOffboardingController::class, 'resign'])->middleware('throttle:10,1')->name('me.resign');
            Route::patch('me/resignation/{resignation}/withdraw', [MyOffboardingController::class, 'withdraw'])->name('me.withdraw');
            Route::get('me/exit-interview', [MyOffboardingController::class, 'interview'])->name('me.interview');
            Route::post('me/exit-interview', [MyOffboardingController::class, 'answer'])->name('me.interview.answer');
            Route::get('me/certificate', [MyOffboardingController::class, 'certificate'])->name('me.certificate');
        });

        // Resignations to review, and what exit interviews say.
        Route::get('resignations', [ResignationController::class, 'index'])->middleware('can:offboarding.view')->name('resignations.index');
        Route::patch('resignations/{resignation}', [ResignationController::class, 'decide'])->middleware('can:offboarding.manage')->name('resignations.decide');
        Route::get('interviews', [ExitInterviewController::class, 'index'])->middleware('can:offboarding.view')->name('interviews.index');

        // Clearance items (addressed by numeric id, like recruitment sub-resources).
        Route::post('clearance/{item}', [ClearanceItemController::class, 'update'])->middleware('can:offboarding.manage')->name('clearance.update');
        Route::patch('clearance/{item}/status', [ClearanceItemController::class, 'toggle'])->middleware('can:offboarding.manage')->name('clearance.status');
        Route::delete('clearance/{item}', [ClearanceItemController::class, 'destroy'])->middleware('can:offboarding.manage')->name('clearance.destroy');

        // A case and its clearance checklist (wildcard — declared last).
        Route::get('{case}', [OffboardingCaseController::class, 'show'])->middleware('can:offboarding.view')->name('show');
        Route::get('{case}/export', ClearanceExportController::class)->middleware('can:offboarding.view')->name('clearance.export');
        // The exit's papers as PDFs (ADR 0077).
        Route::get('{case}/documents/clearance-form', [OffboardingDocumentController::class, 'clearance'])->middleware('can:offboarding.view')->name('documents.clearance');
        Route::get('{case}/documents/certificate', [OffboardingDocumentController::class, 'certificate'])->middleware('can:offboarding.view')->name('documents.certificate');
        // Its exit interview: send one by hand, or stop waiting for it.
        Route::post('{case}/interview', [ExitInterviewController::class, 'send'])->middleware('can:offboarding.manage')->name('interview.send');
        Route::patch('{case}/interview/waive', [ExitInterviewController::class, 'waive'])->middleware('can:offboarding.manage')->name('interview.waive');
        Route::post('{case}', [OffboardingCaseController::class, 'update'])->middleware('can:offboarding.manage')->name('update');
        Route::patch('{case}/status', [OffboardingCaseController::class, 'status'])->middleware('can:offboarding.manage')->name('status');
        Route::delete('{case}', [OffboardingCaseController::class, 'destroy'])->middleware('can:offboarding.manage')->name('destroy');
        Route::post('{case}/clearance', [ClearanceItemController::class, 'store'])->middleware('can:offboarding.manage')->name('clearance.store');
        Route::post('{case}/clearance/apply-program', [ClearanceItemController::class, 'applyProgram'])->middleware('can:offboarding.manage')->name('clearance.apply-program');
        Route::patch('{case}/clearance/bulk-clear', [ClearanceItemController::class, 'bulkClear'])->middleware('can:offboarding.manage')->name('clearance.bulk-clear');
    });
