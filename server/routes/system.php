<?php

use App\Http\Controllers\ActivityLog\ActivityLogBulkActionController;
use App\Http\Controllers\ActivityLog\ActivityLogController;
use App\Http\Controllers\ActivityLog\ActivityLogExportController;
use App\Http\Controllers\UserManagement\UserBulkActionController;
use App\Http\Controllers\UserManagement\UserController;
use App\Http\Controllers\UserManagement\UserExportController;
use App\Http\Controllers\UserManagement\UserPasswordController;
use App\Http\Controllers\UserManagement\UserStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('system')
    ->name('system.')
    ->group(function () {
        // User Management
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::post('/', [UserController::class, 'store'])->name('store');
            Route::get('export', UserExportController::class)->name('export');
            Route::post('bulk', UserBulkActionController::class)->name('bulk');
            Route::patch('{user}', [UserController::class, 'update'])->name('update');
            Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
            Route::patch('{user}/status', [UserStatusController::class, 'update'])->name('status');
            Route::put('{user}/password', [UserPasswordController::class, 'update'])->name('password');
            Route::patch('{user}/restore', [UserController::class, 'restore'])->name('restore');
            Route::delete('{user}/force', [UserController::class, 'forceDelete'])->name('force-delete');
        });

        // Activity Logs
        Route::prefix('activity-logs')->name('activity-logs.')->group(function () {
            Route::get('/', [ActivityLogController::class, 'index'])->name('index');
            Route::get('export', ActivityLogExportController::class)->name('export');
            Route::post('bulk', ActivityLogBulkActionController::class)->name('bulk');
            Route::delete('clear', [ActivityLogController::class, 'clear'])->name('clear');
            Route::delete('{activityLog}', [ActivityLogController::class, 'destroy'])->name('destroy');
        });
    });
