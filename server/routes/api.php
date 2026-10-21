<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AwardController;
use App\Http\Controllers\Api\CalendarFeedController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\InvitationController;
use App\Http\Controllers\Api\LeaveController;
use App\Http\Controllers\Api\OffboardingController;
use App\Http\Controllers\Api\ProblemReportController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\RecognitionController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\TourController;
use App\Http\Controllers\Api\TrainingController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\Public\CalendarFeedController as CalendarSubscriptionController;
use App\Http\Controllers\Public\CertificateDownloadController;
use App\Http\Controllers\Public\TrainingSessionCalendarController;
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

// A forgotten password (ADR 0082): Fortify's reset link, mailed. Public, so it
// answers the same for any address and is capped like the other public posts.
Route::post('auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:6,1')
    ->name('api.auth.forgot-password');

// A person's calendar subscription (ADR 0070). Fetched by calendar apps with no
// session or token header: the secret in the address is the key, so it is
// throttled like the other public lookups.
Route::get('calendar/{token}.ics', CalendarSubscriptionController::class)
    ->where('token', '[A-Za-z0-9]+')
    ->middleware('throttle:60,1')
    ->name('api.calendar.feed');

// A Certificate of Employment opened from the app (ADR 0077). The phone's browser
// has no token: the short-lived signature in the address is the key.
Route::get('documents/certificate/{case}/{user}', CertificateDownloadController::class)
    ->whereNumber('user')
    ->middleware(['signed', 'throttle:20,1'])
    ->name('api.documents.certificate');

// A training session for the phone's calendar (ADR 0094), the same way: the
// browser has no token, so the short-lived signature in the address is the key.
Route::get('documents/training-session/{session}/{user}', TrainingSessionCalendarController::class)
    ->whereNumber(['session', 'user'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('api.documents.training-session');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me'])->name('api.me');
    // An uploaded file, for the app (ADR 0087): the same rules as the web's
    // `files.show`, read from the token's workspace.
    Route::get('files/{path}', [FileController::class, 'show'])->where('path', '.+')->name('api.files.show');
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

    // The app's own tour (ADR 0080): its runs and its answer.
    Route::post('tour/runs', [TourController::class, 'start'])->middleware('throttle:30,1')->name('api.tour.runs.start');
    Route::patch('tour/runs/{run}', [TourController::class, 'reach'])->middleware('throttle:120,1')->name('api.tour.runs.reach');
    Route::post('tour/finish', [TourController::class, 'finish'])->name('api.tour.finish');

    // Search one's own records (ADR 0081), throttled like the web palette.
    Route::get('search', SearchController::class)->middleware('throttle:search')->name('api.search');

    // Problems reported to the SYNAPSE team (ADR 0096) — one's own, whichever
    // workspace they were sent from. Referenced by PR-XXXXXX, never by id.
    Route::get('problem-reports', [ProblemReportController::class, 'index'])->name('api.problem-reports.index');
    Route::post('problem-reports', [ProblemReportController::class, 'store'])->middleware('throttle:problem-reports')->name('api.problem-reports.store');
    Route::get('problem-reports/{reference}', [ProblemReportController::class, 'show'])->name('api.problem-reports.show');

    // Self-service Daily Time Record.
    Route::get('attendance/today', [AttendanceController::class, 'today'])->name('api.attendance.today');
    Route::post('attendance/punch', [AttendanceController::class, 'punch'])->middleware('can:attendance.clock')->name('api.attendance.punch');
    Route::get('attendance/records', [AttendanceController::class, 'records'])->name('api.attendance.records');
    Route::get('attendance/summary', [AttendanceController::class, 'summary'])->name('api.attendance.summary');

    // The holidays one keeps over the coming year, a local one included (ADR 0078).
    Route::get('holidays', [HolidayController::class, 'index'])->name('api.holidays.index');

    // The employee's own 201 profile and recognitions.
    Route::get('profile', [ProfileController::class, 'show'])->name('api.profile.show');
    Route::get('awards', [AwardController::class, 'index'])->name('api.awards.index');

    // One's own sign-in account (ADR 0082) — the name, email and password, never
    // the HR record — and one's own week of shifts (ADR 0083).
    Route::get('account', [AccountController::class, 'show'])->name('api.account.show');
    Route::patch('account', [AccountController::class, 'update'])->middleware('throttle:10,1')->name('api.account.update');
    Route::put('account/password', [AccountController::class, 'password'])->middleware('throttle:6,1')->name('api.account.password');
    Route::get('schedule', [ScheduleController::class, 'show'])->name('api.schedule.show');

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

    // Training (ADR 0094): one's own programs, sessions and certificates, the
    // programs one may join, and joining or withdrawing.
    Route::middleware('can:training.participate')->group(function () {
        Route::get('training', [TrainingController::class, 'index'])->name('api.training.index');
        Route::get('training/catalog', [TrainingController::class, 'catalog'])->name('api.training.catalog');
        Route::get('training/programs/{trainingProgram}', [TrainingController::class, 'show'])->name('api.training.show');
        Route::post('training/programs/{trainingProgram}/join', [TrainingController::class, 'join'])->middleware('throttle:20,1')->name('api.training.join');
        Route::delete('training/enrollments/{enrollment}', [TrainingController::class, 'withdraw'])->whereNumber('enrollment')->name('api.training.withdraw');
        Route::post('training/sessions/{session}/calendar-link', [TrainingController::class, 'sessionLink'])->whereNumber('session')->middleware('throttle:30,1')->name('api.training.session-link');
        Route::get('training/certificates/{certificate}', [TrainingController::class, 'certificate'])->whereNumber('certificate')->name('api.training.certificate');
    });

    // Leaving (ADRs 0076, 0077): one's own resignation, exit interview and
    // Certificate of Employment.
    Route::middleware('can:offboarding.resign')->group(function () {
        Route::get('offboarding', [OffboardingController::class, 'show'])->name('api.offboarding.show');
        Route::post('offboarding/resignation', [OffboardingController::class, 'resign'])->middleware('throttle:10,1')->name('api.offboarding.resign');
        Route::patch('offboarding/resignation/{resignation}/withdraw', [OffboardingController::class, 'withdraw'])->name('api.offboarding.withdraw');
        Route::get('offboarding/exit-interview', [OffboardingController::class, 'interview'])->name('api.offboarding.interview');
        Route::post('offboarding/exit-interview', [OffboardingController::class, 'answer'])->name('api.offboarding.interview.answer');
        Route::post('offboarding/certificate-link', [OffboardingController::class, 'certificateLink'])->middleware('throttle:20,1')->name('api.offboarding.certificate-link');
    });

    // Self-service Leave. Literal routes precede the {leaveRequest} wildcard.
    Route::get('leave/types', [LeaveController::class, 'types'])->name('api.leave.types');
    Route::get('leave/balances', [LeaveController::class, 'balances'])->name('api.leave.balances');
    Route::get('leave/requests', [LeaveController::class, 'index'])->name('api.leave.index');
    Route::post('leave/requests', [LeaveController::class, 'store'])->middleware('can:leave.request')->name('api.leave.store');
    Route::patch('leave/requests/{leaveRequest}/cancel', [LeaveController::class, 'cancel'])
        ->whereNumber('leaveRequest')->middleware('can:leave.request')->name('api.leave.cancel');
});
