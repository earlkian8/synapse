<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AwardController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

/*
| Mobile API — token-authenticated (Sanctum) surface for the DTR mobile app. The
| web uses session-based Fortify auth; mobile clients exchange credentials for a
| personal access token at /api/auth/login and send it as a Bearer token. The
| `api` middleware group binds the current tenant after auth:sanctum resolves the
| token's user (see bootstrap/app.php).
*/

// Brute-force protection: the web login is throttled by Fortify; this token
// endpoint is public too, so cap attempts (per email+IP, see RouteServiceProvider
// / FortifyServiceProvider 'login' limiter) the same way.
Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('api.auth.login');

// People create their own accounts (ADR 0026) — the ERP no longer issues logins.
// Registering joins no company; the session comes back with `needs_workspace`.
Route::post('auth/register', [AuthController::class, 'register'])
    ->middleware('throttle:6,1')
    ->name('api.auth.register');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('api.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    // Switch the active organisation (employees of more than one company).
    Route::post('auth/switch', [AuthController::class, 'switch'])->name('api.auth.switch');

    // The two ways into a company (ADR 0026). Both are guessing targets — a join
    // code names a real organisation and an invitation code grants a seat in one —
    // so every lookup here is rate-limited, not just the ones that mutate.
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('workspaces/preview', [WorkspaceController::class, 'preview'])->name('api.workspaces.preview');
        Route::post('workspaces/join', [WorkspaceController::class, 'join'])->name('api.workspaces.join');
        Route::post('invitations/preview', [InvitationController::class, 'preview'])->name('api.invitations.preview');
        Route::post('invitations/accept', [InvitationController::class, 'accept'])->name('api.invitations.accept');
    });

    Route::get('invitations', [InvitationController::class, 'index'])->name('api.invitations.index');
    Route::delete('invitations/{invitation}', [InvitationController::class, 'decline'])
        ->whereNumber('invitation')->name('api.invitations.decline');

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
