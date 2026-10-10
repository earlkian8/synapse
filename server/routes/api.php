<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AwardController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecognitionController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Public\CalendarFeedController as CalendarSubscriptionController;
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

// A person's calendar subscription (ADR 0070). Fetched by calendar apps with no
// session or token header: the secret in the address is the key, so it is
// throttled like the other public lookups.
Route::get('calendar/{token}.ics', CalendarSubscriptionController::class)
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('api.calendar.feed');

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

    // My events (ADR 0070): own invitations, answered from the phone, and the
    // calendar subscription link.
    Route::middleware('can:events.respond')->group(function () {
        Route::get('events', [EventController::class, 'index'])->name('api.events.index');
        Route::get('events/{event}', [EventController::class, 'show'])->name('api.events.show');
        Route::post('events/{event}/respond', [EventController::class, 'respond'])->name('api.events.respond');
        Route::get('calendar-feed', [CalendarFeedController::class, 'show'])->name('api.calendar-feed.show');
        Route::post('calendar-feed/reset', [CalendarFeedController::class, 'reset'])->name('api.calendar-feed.reset');
    });

    // Recognition (ADR 0071): the wall, kudos, nominations, points and rewards.
    Route::middleware('can:awards.participate')->group(function () {
        Route::get('recognition', [RecognitionController::class, 'wall'])->name('api.recognition.wall');
        Route::get('colleagues', [RecognitionController::class, 'colleagues'])->name('api.colleagues');
        Route::post('kudos', [RecognitionController::class, 'kudos'])->middleware('throttle:30,1')->name('api.kudos.store');
        Route::get('nominations', [RecognitionController::class, 'nominations'])->name('api.nominations.index');
        Route::get('nominations/types', [RecognitionController::class, 'nominationTypes'])->name('api.nominations.types');
        Route::post('nominations', [RecognitionController::class, 'nominate'])->middleware('throttle:20,1')->name('api.nominations.store');
        Route::delete('nominations/{nomination}', [RecognitionController::class, 'withdraw'])->whereNumber('nomination')->name('api.nominations.withdraw');
        Route::get('points', [RecognitionController::class, 'points'])->name('api.points');
        Route::get('rewards', [RecognitionController::class, 'rewards'])->name('api.rewards.index');
        Route::post('rewards/{reward}/redeem', [RecognitionController::class, 'redeem'])->middleware('throttle:20,1')->name('api.rewards.redeem');
        Route::patch('redemptions/{redemption}/cancel', [RecognitionController::class, 'cancel'])->whereNumber('redemption')->name('api.redemptions.cancel');
    });

    // Self-service Leave. Literal routes precede the {leaveRequest} wildcard.
    Route::get('leave/types', [LeaveController::class, 'types'])->name('api.leave.types');
    Route::get('leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('leave/requests', [LeaveController::class, 'store'])->middleware('can:leave.request')->name('api.leave.store');
    Route::patch('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
        ->whereNumber('leaveRequest')->middleware('can:leave.request')->name('api.leave.cancel');
});
