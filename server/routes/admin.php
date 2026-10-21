<?php

use App\Http\Controllers\Admin\AccountController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminAccountController;
use App\Http\Controllers\Admin\AdminSessionController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\ProblemReportController;
use Illuminate\Support\Facades\Route;

/*
| The platform admin portal's API (ADR 0085), mounted at /api/admin with the
| `admin-api` group (bootstrap/app.php): no session, no cookies, no tenant —
| bearer tokens on the `admin` guard, and nothing here is ever cached.
|
| Signing in takes two calls and only the second opens a session. Everything
| else needs that session (`auth:admin` + `admin.session`), a password the admin
| chose themselves (`admin.password-current`), and for admin accounts and the
| activity log, a super admin (`admin.super`).
*/

Route::post('auth/login', [AdminSessionController::class, 'login'])
    ->middleware('throttle:admin-login')
    ->name('auth.login');

Route::post('auth/two-factor', [AdminSessionController::class, 'twoFactor'])
    ->middleware('throttle:admin-two-factor')
    ->name('auth.two-factor');

Route::middleware(['auth:admin', 'admin.session', 'throttle:admin-api'])->group(function () {
    Route::get('me', [AdminSessionController::class, 'me'])->name('me');
    Route::post('auth/logout', [AdminSessionController::class, 'logout'])->name('auth.logout');

    // One's own account — open even while a password change is owed.
    Route::put('account/password', [AdminAccountController::class, 'password'])
        ->middleware('throttle:6,1')
        ->name('account.password');
    Route::post('account/sessions/revoke-others', [AdminAccountController::class, 'revokeOtherSessions'])
        ->name('account.sessions.revoke-others');

    Route::middleware('admin.password-current')->group(function () {
        // Monitoring — read-only.
        Route::get('overview', OverviewController::class)->name('overview');
        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])
            ->whereNumber('organization')->name('organizations.show');
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
        Route::get('accounts/{account}', [AccountController::class, 'show'])
            ->whereNumber('account')->name('accounts.show');

        // Support (ADR 0096) — problem reports from the ERP and the app, which
        // every admin follows up.
        Route::get('problem-reports', [ProblemReportController::class, 'index'])->name('problem-reports.index');
        Route::get('problem-reports/counts', [ProblemReportController::class, 'counts'])->name('problem-reports.counts');
        Route::get('problem-reports/{report}', [ProblemReportController::class, 'show'])
            ->whereNumber('report')->name('problem-reports.show');
        Route::patch('problem-reports/{report}', [ProblemReportController::class, 'update'])
            ->whereNumber('report')->middleware('throttle:60,1')->name('problem-reports.update');
        Route::get('problem-reports/{report}/attachments/{attachment}', [ProblemReportController::class, 'attachment'])
            ->whereNumber(['report', 'attachment'])->name('problem-reports.attachment');

        Route::middleware('admin.super')->group(function () {
            Route::get('admins', [AdminUserController::class, 'index'])->name('admins.index');
            Route::post('admins', [AdminUserController::class, 'store'])->name('admins.store');
            Route::patch('admins/{admin}', [AdminUserController::class, 'update'])->whereNumber('admin')->name('admins.update');
            Route::patch('admins/{admin}/status', [AdminUserController::class, 'status'])->whereNumber('admin')->name('admins.status');
            Route::post('admins/{admin}/reset-password', [AdminUserController::class, 'resetPassword'])->whereNumber('admin')->name('admins.reset-password');
            Route::post('admins/{admin}/reset-two-factor', [AdminUserController::class, 'resetTwoFactor'])->whereNumber('admin')->name('admins.reset-two-factor');
            Route::delete('admins/{admin}', [AdminUserController::class, 'destroy'])->whereNumber('admin')->name('admins.destroy');

            Route::get('activity', ActivityLogController::class)->name('activity.index');
        });
    });
});
