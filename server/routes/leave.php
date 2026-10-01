<?php

use App\Http\Controllers\Leave\LeaveBalanceController;
use App\Http\Controllers\Leave\LeaveRequestController;
use Illuminate\Support\Facades\Route;

/*
| Leave Management — employees' time off, with an approval lifecycle. The inbox
| (requests) and the balances view live here; the leave *types* are configured
| under Company Setup (routes/setup.php). Requests are addressed by hashid. The
| literal `balances` routes are declared before the `{leaveRequest}` wildcard so
| they resolve correctly. Every route is permission-gated.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('leave')
    ->name('leave.')
    ->group(function () {
        Route::get('/', [LeaveRequestController::class, 'index'])->middleware('can:leave.view')->name('index');
        Route::post('/', [LeaveRequestController::class, 'store'])->middleware('can:leave.request')->name('store');

        // Balances (literal — before the wildcard).
        Route::get('balances', [LeaveBalanceController::class, 'index'])->middleware('can:leave.view')->name('balances.index');
        Route::post('balances', [LeaveBalanceController::class, 'store'])->middleware('can:leave.manage')->name('balances.store');

        // A single request and its lifecycle (wildcard — declared last).
        Route::get('{leaveRequest}', [LeaveRequestController::class, 'show'])->middleware('can:leave.view')->name('show');
        Route::post('{leaveRequest}', [LeaveRequestController::class, 'update'])->middleware('can:leave.request')->name('update');
        Route::patch('{leaveRequest}/review', [LeaveRequestController::class, 'review'])->middleware('can:leave.manage')->name('review');
        Route::patch('{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel'])->middleware('can:leave.request')->name('cancel');
        Route::delete('{leaveRequest}', [LeaveRequestController::class, 'destroy'])->middleware('can:leave.manage')->name('destroy');
    });
