<?php

use App\Http\Controllers\Events\EventAttendeeController;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\Events\EventExportController;
use App\Http\Controllers\Events\EventIcsController;
use App\Http\Controllers\Events\EventManagementController;
use App\Http\Controllers\Events\EventRosterExportController;
use Illuminate\Support\Facades\Route;

/*
| Events & Meetings — the organisation's scheduled events / meetings and who is
| invited to each. Events are created in-module (no Company Setup) and addressed by
| hashid; attendees by numeric id. Viewing needs `events.view`; scheduling events /
| inviting / updating responses needs `events.manage`.
*/
Route::middleware(['auth', 'verified'])
    ->prefix('events')
    ->name('events.')
    ->group(function () {
        Route::get('/', [EventController::class, 'index'])->middleware('can:events.view')->name('index');
        Route::post('/', [EventManagementController::class, 'store'])->middleware('can:events.manage')->name('store');

        // CSV export of every event (literal — declared before the wildcard).
        Route::get('export', EventExportController::class)->middleware('can:events.view')->name('export');

        // Attendee mutations (literal "attendees" — declared before the {event}
        // wildcard so it never binds to it).
        Route::patch('attendees/{attendee}', [EventAttendeeController::class, 'update'])->middleware('can:events.manage')->name('attendees.update');
        Route::delete('attendees/{attendee}', [EventAttendeeController::class, 'destroy'])->middleware('can:events.manage')->name('attendees.destroy');

        // A single event and its roster.
        Route::get('{event}', [EventController::class, 'show'])->middleware('can:events.view')->name('show');
        Route::get('{event}/export', EventRosterExportController::class)->middleware('can:events.view')->name('roster-export');
        Route::get('{event}/ics', EventIcsController::class)->middleware('can:events.view')->name('ics');
        Route::post('{event}/duplicate', [EventManagementController::class, 'duplicate'])->middleware('can:events.manage')->name('duplicate');
        Route::post('{event}', [EventManagementController::class, 'update'])->middleware('can:events.manage')->name('update');
        Route::delete('{event}', [EventManagementController::class, 'destroy'])->middleware('can:events.manage')->name('destroy');
        Route::patch('{event}/restore', [EventManagementController::class, 'restore'])->middleware('can:events.manage')->name('restore');
        Route::delete('{event}/force', [EventManagementController::class, 'forceDelete'])->middleware('can:events.manage')->name('force-delete');

        Route::post('{event}/attendees', [EventAttendeeController::class, 'store'])->middleware('can:events.manage')->name('attendees.store');
        Route::post('{event}/remind', [EventAttendeeController::class, 'remind'])->middleware('can:events.manage')->name('remind');
    });
