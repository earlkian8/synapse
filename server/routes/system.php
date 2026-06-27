<?php

use App\Http\Controllers\ActivityLog\ActivityLogBulkActionController;
use App\Http\Controllers\ActivityLog\ActivityLogController;
use App\Http\Controllers\ActivityLog\ActivityLogExportController;
use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Notification\NotificationPreferenceController;
use App\Http\Controllers\Notification\PushSubscriptionController;
use App\Http\Controllers\RolePermission\RoleBulkActionController;
use App\Http\Controllers\RolePermission\RoleController;
use App\Http\Controllers\RolePermission\RoleExportController;
use App\Http\Controllers\System\TrashController;
use App\Http\Controllers\UserManagement\UserBulkActionController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Controllers\UserManagement\UserExportController;
use App\Http\Controllers\UserManagement\UserImportController;
use App\Http\Controllers\UserManagement\UserPasswordController;
use App\Http\Controllers\UserManagement\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('system')
    ->name('system.')
    ->group(function () {
        // User Management
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->middleware('can:users.view')->name('index');
            Route::post('/', [UserController::class, 'store'])->middleware('can:users.create')->name('store');
            Route::get('export', UserExportController::class)->middleware('can:users.export')->name('export');
            Route::get('import/template', [UserImportController::class, 'template'])->middleware('can:users.create')->name('import.template');
            Route::post('import', [UserImportController::class, 'store'])->middleware('can:users.create')->name('import.store');
            Route::post('bulk', UserBulkActionController::class)->middleware('can:users.view')->name('bulk');
            Route::patch('{user}', [UserController::class, 'update'])->middleware('can:users.update')->name('update');
            Route::post('{user}/resend-verification', [UserController::class, 'resendVerification'])->middleware('can:users.update')->name('resend-verification');
            Route::delete('{user}', [UserController::class, 'destroy'])->middleware('can:users.delete')->name('destroy');
            Route::patch('{user}/status', [UserStatusController::class, 'update'])->middleware('can:users.manage-status')->name('status');
            Route::put('{user}/password', [UserPasswordController::class, 'update'])->middleware('can:users.reset-password')->name('password');
            Route::patch('{user}/restore', [UserController::class, 'restore'])->middleware('can:users.restore')->name('restore');
            Route::delete('{user}/force', [UserController::class, 'forceDelete'])->middleware('can:users.force-delete')->name('force-delete');
        });

        // Roles & Permissions
        Route::prefix('roles')->name('roles.')->group(function () {
            Route::get('/', [RoleController::class, 'index'])->middleware('can:roles.view')->name('index');
            Route::get('export', RoleExportController::class)->middleware('can:roles.view')->name('export');
            Route::post('bulk', RoleBulkActionController::class)->middleware('can:roles.view')->name('bulk');
            Route::post('/', [RoleController::class, 'store'])->middleware('can:roles.create')->name('store');
            Route::patch('{role}', [RoleController::class, 'update'])->middleware('can:roles.update')->name('update');
            Route::delete('{role}', [RoleController::class, 'destroy'])->middleware('can:roles.delete')->name('destroy');
        });

        // Notifications — personal to each user; only broadcasting is gated.
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::post('/', [NotificationController::class, 'store'])->middleware('can:notifications.send')->name('store');
            Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
            Route::delete('clear', [NotificationController::class, 'clear'])->name('clear');
            Route::patch('{notification}/read', [NotificationController::class, 'read'])->name('read');
            Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
            Route::put('preferences', [NotificationPreferenceController::class, 'update'])->name('preferences');
            Route::post('subscriptions', [PushSubscriptionController::class, 'store'])->name('subscriptions.store');
            Route::delete('subscriptions', [PushSubscriptionController::class, 'destroy'])->name('subscriptions.destroy');
        });

        // Trash Bin — archived records across modules. Visibility + each action
        // are authorised per item type inside the controller (RBAC by owning
        // module), so no static `can:` middleware fits here.
        Route::prefix('trash')->name('trash.')->group(function () {
            Route::get('/', [TrashController::class, 'index'])->name('index');
            Route::post('restore', [TrashController::class, 'restore'])->name('restore');
            Route::post('force-delete', [TrashController::class, 'forceDelete'])->name('force-delete');
            Route::post('bulk', [TrashController::class, 'bulk'])->name('bulk');
            Route::post('empty', [TrashController::class, 'empty'])->name('empty');
        });

        // Activity Logs
        Route::prefix('activity-logs')->name('activity-logs.')->group(function () {
            Route::get('/', [ActivityLogController::class, 'index'])->middleware('can:activity-logs.view')->name('index');
            Route::get('export', ActivityLogExportController::class)->middleware('can:activity-logs.export')->name('export');
            Route::post('bulk', ActivityLogBulkActionController::class)->middleware('can:activity-logs.delete')->name('bulk');
            Route::delete('clear', [ActivityLogController::class, 'clear'])->middleware('can:activity-logs.delete')->name('clear');
            Route::delete('{activityLog}', [ActivityLogController::class, 'destroy'])->middleware('can:activity-logs.delete')->name('destroy');
        });
    });
