<?php

use App\Http\Controllers\Payroll\PayrollController;
use Illuminate\Support\Facades\Route;

/*
| Payroll — pay runs (periods) and their per-employee payslips. Runs are
| addressed by hashid. Every route is permission-gated: viewing needs
| `payroll.view`, creating / re-processing needs `payroll.process`, and the
| finalize / mark-paid lifecycle needs `payroll.release`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('payroll')
    ->name('payroll.')
    ->group(function () {
        Route::get('/', [PayrollController::class, 'index'])->middleware('can:payroll.view')->name('index');
        Route::post('/', [PayrollController::class, 'store'])->middleware('can:payroll.process')->name('store');

        // A single run and its lifecycle (wildcard — declared after literals).
        Route::get('{payrollPeriod}', [PayrollController::class, 'show'])->middleware('can:payroll.view')->name('show');
        Route::patch('{payrollPeriod}/process', [PayrollController::class, 'process'])->middleware('can:payroll.process')->name('process');
        Route::patch('{payrollPeriod}/finalize', [PayrollController::class, 'finalize'])->middleware('can:payroll.release')->name('finalize');
        Route::patch('{payrollPeriod}/paid', [PayrollController::class, 'markPaid'])->middleware('can:payroll.release')->name('paid');
        Route::delete('{payrollPeriod}', [PayrollController::class, 'destroy'])->middleware('can:payroll.process')->name('destroy');
    });
