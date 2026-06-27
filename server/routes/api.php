<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AwardController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API — token-authenticated (Sanctum) surface for the DTR mobile app. The
| web uses session-based Fortify auth; mobile clients exchange credentials for a
| personal access token at /api/auth/login and send it as a Bearer token. The
| `api` middleware group binds the current tenant after auth:sanctum resolves the
| token's user (see bootstrap/app.php).
*/

Route::post('auth/login', [AuthController::class, 'login'])->name('api.auth.login');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('api.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

    // Self-service Daily Time Record.
    Route::get('attendance/today', [AttendanceController::class, 'today'])->name('api.attendance.today');
    Route::post('attendance/punch', [AttendanceController::class, 'punch'])->middleware('can:attendance.clock')->name('api.attendance.punch');
    Route::get('attendance/records', [AttendanceController::class, 'records'])->name('api.attendance.records');
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('api.attendance.summary');

    // The employee's own 201 profile and recognitions.
    Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::get('awards', [AwardController::class, 'index'])->name('api.awards.index');

    // Self-service Leave. Literal routes precede the {leaveRequest} wildcard.
    Route::get('leave/types', [LeaveController::class, 'types'])->name('api.leave.types');
    Route::get('leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('leave/requests', [LeaveController::class, 'store'])->middleware('can:leave.request')->name('api.leave.store');
    Route::patch('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
        ->whereNumber('leaveRequest')->middleware('can:leave.request')->name('api.leave.cancel');
});
