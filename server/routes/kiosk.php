<?php

use App\Http\Controllers\Attendance\QrWebScanController;
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

/*
| Scanning a QR station with any phone's camera, no app (ADR 0103). The code's
| web address opens the page; nobody signs in. The page claims the live code
| for this browser, then the number and PIN, a PIN of one's own, and the punch
| go to `qr-scan/*`, which answer JSON. Throttled per address; a person's PIN
| locks after wrong tries and a station pauses after many (KioskPins).
*/
Route::get('q/{code}', [QrWebScanController::class, 'show'])
    ->middleware('throttle:qr-scan-page')
    ->where('code', '[A-Za-z0-9._-]{8,120}')
    ->name('qr-scan.show');

Route::prefix('qr-scan')->name('qr-scan.')->middleware('throttle:qr-scan')->group(function () {
    Route::post('identify', [QrWebScanController::class, 'identify'])->name('identify');
    Route::post('pin', [QrWebScanController::class, 'pin'])->name('pin');
    Route::post('punch', [QrWebScanController::class, 'punch'])->name('punch');
});
