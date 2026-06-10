<?php

use App\Http\Controllers\Notification\NotificationController;
use App\Http\Controllers\Notification\NotificationPreferenceController;
use App\Http\Controllers\Notification\PushSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
| Notifications are personal: every authenticated user has their own centre.
| Only composing/broadcasting to others is permission-gated.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function () {
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
