<?php

use App\Http\Controllers\Attendance\AttendanceController;
use App\Http\Controllers\Attendance\AttendanceExportController;
use App\Http\Controllers\Attendance\KioskController;
use App\Http\Controllers\Attendance\KioskPinController;
use App\Http\Controllers\Attendance\MyAttendanceController;
use App\Http\Controllers\Attendance\ShiftRosterController;
use Illuminate\Support\Facades\Route;

/*
| Attendance — the Daily Time Record (DTR). The HR board (everyone's day), the
| shift roster (everyone's plan, ADR 0090), the kiosks people without a phone
| clock in at (ADR 0092) and the employee self-service clock live here; records
| are addressed by hashid. The literal `me` / `roster` / `kiosks` / `records`
| segments are declared before the `{attendanceRecord}` wildcard so they resolve
| correctly. Every route is permission-gated (the board's own landing checks
| inside, to send a roster- or kiosk-only manager to theirs). The mobile-facing
| equivalents are token-authenticated in routes/api.php; the kiosk device's own
| routes are in routes/kiosk.php.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('attendance')
    ->name('attendance.')
    ->group(function () {
        // HR daily board. Somebody who may only see the roster is sent to it.
        Route::get('/', [AttendanceController::class, 'index'])->name('index');
        Route::post('/', [AttendanceController::class, 'store'])->middleware('can:attendance.manage')->name('store');

        // Board-wide actions (literal segments — declared before the wildcard).
        Route::get('export', AttendanceExportController::class)->middleware('can:attendance.view')->name('export');
        Route::patch('approve-all', [AttendanceController::class, 'approveAll'])->middleware('can:attendance.manage')->name('approve-all');
        Route::patch('reapply-schedule', [AttendanceController::class, 'reapplyRange'])->middleware('can:attendance.manage')->name('reapply-range');

        // Shift Roster — who is due to work what, day by day (ADR 0037, 0090): the
        // plan each day is judged against. Overrides are addressed by hashid.
        Route::get('roster', [ShiftRosterController::class, 'index'])->middleware('can:attendance.roster.view')->name('roster.index');
        Route::post('roster/entries', [ShiftRosterController::class, 'store'])->middleware('can:attendance.roster.manage')->name('roster.store');
        Route::delete('roster/entries/{shiftRosterEntry}', [ShiftRosterController::class, 'destroy'])->middleware('can:attendance.roster.manage')->name('roster.destroy');
        Route::post('roster/assign', [ShiftRosterController::class, 'assign'])->middleware('can:attendance.roster.manage')->name('roster.assign');

        // Kiosks (ADR 0092) — the shared devices at a site, and who clocks in at
        // them with a PIN. Pairing codes and PINs are answered as JSON, once.
        // Kiosks by hashid; a PIN by its numeric id (a sub-resource).
        Route::get('kiosks', [KioskController::class, 'index'])->middleware('can:attendance.kiosks.manage')->name('kiosks.index');
        Route::post('kiosks', [KioskController::class, 'store'])->middleware('can:attendance.kiosks.manage')->name('kiosks.store');
        Route::post('kiosks/pins', [KioskPinController::class, 'store'])->middleware(['can:attendance.kiosks.manage', 'throttle:30,1'])->name('kiosks.pins.store');
        Route::delete('kiosks/pins/{employeeKioskPin}', [KioskPinController::class, 'destroy'])->middleware('can:attendance.kiosks.manage')->name('kiosks.pins.destroy');
        Route::post('kiosks/{attendanceKiosk}', [KioskController::class, 'update'])->middleware('can:attendance.kiosks.manage')->name('kiosks.update');
        Route::post('kiosks/{attendanceKiosk}/pairing-code', [KioskController::class, 'pairingCode'])->middleware(['can:attendance.kiosks.manage', 'throttle:30,1'])->name('kiosks.pairing-code');
        Route::delete('kiosks/{attendanceKiosk}/device', [KioskController::class, 'unpair'])->middleware('can:attendance.kiosks.manage')->name('kiosks.unpair');
        Route::delete('kiosks/{attendanceKiosk}', [KioskController::class, 'destroy'])->middleware('can:attendance.kiosks.manage')->name('kiosks.destroy');

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
