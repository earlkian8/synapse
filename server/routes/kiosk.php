<?php

use App\Http\Controllers\Kiosk\KioskDeviceController;
use App\Http\Middleware\AuthenticateKiosk;
use Illuminate\Support\Facades\Route;

/*
| The kiosk device (ADR 0092) — a shared tablet at a site, where people who have
| no phone clock in. Nobody signs in on it, so none of this is behind `auth`:
|
|  - the page reads the device's cookie to say which screen to show;
|  - pairing takes the one-time code HR was given (throttled per address, since
|    a code is only safe while guessing it is slow);
|  - everything else is the paired device, known by its cookie
|    (AuthenticateKiosk), which also binds its company as the tenant, and
|    throttled per device.
|
| All of it is in the web group, so a post needs the form token like any other.
*/
Route::prefix('kiosk')->name('kiosk.')->group(function () {
    Route::get('/', [KioskDeviceController::class, 'show'])->name('show');
    Route::post('pair', [KioskDeviceController::class, 'pair'])->middleware('throttle:kiosk-pairing')->name('pair');

    Route::middleware([AuthenticateKiosk::class, 'throttle:kiosk'])->group(function () {
        Route::get('state', [KioskDeviceController::class, 'state'])->name('state');
        Route::post('sign-in', [KioskDeviceController::class, 'signIn'])->name('sign-in');
        Route::post('pin', [KioskDeviceController::class, 'changePin'])->name('pin');
        Route::post('punch', [KioskDeviceController::class, 'punch'])->name('punch');
        Route::post('done', [KioskDeviceController::class, 'done'])->name('done');
    });
});
