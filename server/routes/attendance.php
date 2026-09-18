<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceExportController;
use App\Http\Controllers\Attendance\AttendancePeriodController;
use App\Http\Controllers\Attendance\AttendanceRequestController;
use App\Http\Controllers\Attendance\MyAttendanceController;
use App\Http\Controllers\Attendance\ShiftRosterController;
use Illuminate\Support\Facades\Route;

/*
| Attendance — the Daily Time Record (DTR). The HR board (everyone's day), the
| shift roster (everyone's plan), requests and periods (ADR 0039) and the
| employee self-service clock live here; records, roster entries, requests and
| periods are addressed by hashid. The
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
        Route::patch('reapply-schedule', [AttendanceController::class, 'reapplyRange'])->middleware('can:attendance.manage')->name('reapply-range');

        // The roster — who is due to work what (ADR 0037). Literal segments, so
        // they are declared with the other board-wide actions.
        Route::post('roster/entries', [ShiftRosterController::class, 'store'])->middleware('can:attendance.roster.manage')->name('roster.store');
        Route::delete('roster/entries/{shiftRosterEntry}', [ShiftRosterController::class, 'destroy'])->middleware('can:attendance.roster.manage')->name('roster.destroy');
        Route::post('roster/assign', [ShiftRosterController::class, 'assign'])->middleware('can:attendance.roster.manage')->name('roster.assign');

        // Requests (ADR 0039) — filed by the employee (or HR on their behalf),
        // decided by a reviewer. Literal `review` before the wildcard. Viewing and
        // cancelling one are checked against the request itself: its employee, its
        // filer, or a reviewer.
        Route::post('requests', [AttendanceRequestController::class, 'store'])->middleware('can:attendance.request')->name('requests.store');
        Route::patch('requests/review', [AttendanceRequestController::class, 'bulkReview'])->middleware('can:attendance.requests.review')->name('requests.bulk-review');
        Route::get('requests/{attendanceRequest}', [AttendanceRequestController::class, 'show'])->name('requests.show');
        Route::patch('requests/{attendanceRequest}/review', [AttendanceRequestController::class, 'review'])->middleware('can:attendance.requests.review')->name('requests.review');
        Route::patch('requests/{attendanceRequest}/cancel', [AttendanceRequestController::class, 'cancel'])->name('requests.cancel');

        // Periods and the lock (ADR 0039). Unlocking is its own permission.
        Route::post('periods/generate', [AttendancePeriodController::class, 'generate'])->middleware('can:attendance.period.manage')->name('periods.generate');
        Route::patch('periods/settings', [AttendancePeriodController::class, 'settings'])->middleware('can:attendance.period.manage')->name('periods.settings');
        Route::post('periods/{attendancePeriod}/lock', [AttendancePeriodController::class, 'lock'])->middleware('can:attendance.period.manage')->name('periods.lock');
        Route::post('periods/{attendancePeriod}/unlock', [AttendancePeriodController::class, 'unlock'])->middleware('can:attendance.period.unlock')->name('periods.unlock');
        Route::get('periods/{attendancePeriod}/export', [AttendancePeriodController::class, 'export'])->middleware('can:attendance.period.manage')->name('periods.export');

        // Self-service (any authenticated user linked to an employee).
        Route::get('me', [MyAttendanceController::class, 'index'])->name('me');
        Route::post('me/punch', [MyAttendanceController::class, 'punch'])->middleware('can:attendance.clock')->name('me.punch');

        // A single record and its lifecycle (wildcard — declared last).
        Route::get('records/{attendanceRecord}', [AttendanceController::class, 'show'])->middleware('can:attendance.view')->name('show');
        Route::post('records/{attendanceRecord}', [AttendanceController::class, 'update'])->middleware('can:attendance.manage')->name('update');
        Route::patch('records/{attendanceRecord}/approve', [AttendanceController::class, 'approve'])->middleware('can:attendance.manage')->name('approve');
        Route::patch('records/{attendanceRecord}/reapply-schedule', [AttendanceController::class, 'reapply'])->middleware('can:attendance.manage')->name('reapply');
        Route::delete('records/{attendanceRecord}', [AttendanceController::class, 'destroy'])->middleware('can:attendance.manage')->name('destroy');
    });
