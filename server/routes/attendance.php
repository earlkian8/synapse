<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceExportController;
use App\Http\Controllers\Attendance\MyAttendanceController;
use Illuminate\Support\Facades\Route;

/*
| Attendance — the Daily Time Record (DTR). The HR board (everyone's day) and the
| employee self-service clock live here; records are addressed by hashid. The
| literal `me` / `records` segments are declared before the `{attendanceRecord}`
| wildcard so they resolve correctly. Every route is permission-gated. The
| mobile-facing equivalents are token-authenticated in routes/api.php.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('attendance')
    ->name('attendance.')
    ->group(function () {
        // HR daily board.
        Route::get('/', [AttendanceController::class, 'index'])->middleware('can:attendance.view')->name('index');
        Route::post('/', [AttendanceController::class, 'store'])->middleware('can:attendance.manage')->name('store');

        // Board-wide actions (literal segments — declared before the wildcard).
        Route::get('export', AttendanceExportController::class)->middleware('can:attendance.view')->name('export');
        Route::patch('approve-all', [AttendanceController::class, 'approveAll'])->middleware('can:attendance.manage')->name('approve-all');

        // Self-service (any authenticated user linked to an employee).
        Route::get('me', [MyAttendanceController::class, 'index'])->name('me');
        Route::post('me/punch', [MyAttendanceController::class, 'punch'])->middleware('can:attendance.clock')->name('me.punch');

        // A single record and its lifecycle (wildcard — declared last).
        Route::get('records/{attendanceRecord}', [AttendanceController::class, 'show'])->middleware('can:attendance.view')->name('show');
        Route::post('records/{attendanceRecord}', [AttendanceController::class, 'update'])->middleware('can:attendance.manage')->name('update');
        Route::patch('records/{attendanceRecord}/approve', [AttendanceController::class, 'approve'])->middleware('can:attendance.manage')->name('approve');
        Route::delete('records/{attendanceRecord}', [AttendanceController::class, 'destroy'])->middleware('can:attendance.manage')->name('destroy');
    });
