<?php

use App\Http\Controllers\Events\EventAttendeeController;
use App\Http\Controllers\Events\EventController;
use App\Http\Controllers\Events\EventExportController;
use App\Http\Controllers\Events\EventIcsController;
use App\Http\Controllers\Events\EventManagementController;
use App\Http\Controllers\Events\EventRosterExportController;
use App\Http\Controllers\Events\MyEventsController;
use App\Http\Controllers\Events\RoomController;
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
        // `events.view`, or `events.respond` (redirected to My invitations).
        Route::get('/', [EventController::class, 'index'])->name('index');
        Route::post('/', [EventManagementController::class, 'store'])->middleware('can:events.manage')->name('store');

        // My events (ADR 0070): an employee's own invitations, answered by
        // themselves, and their calendar subscription. Literal — before the
        // {event} wildcard. An event is reachable here only by its invitees.
        Route::middleware('can:events.respond')->prefix('me')->name('me')->group(function () {
            Route::get('/', [MyEventsController::class, 'index']);
            Route::get('calendar', [MyEventsController::class, 'calendar'])->name('.calendar');
            Route::post('calendar/reset', [MyEventsController::class, 'resetCalendar'])->name('.calendar.reset');
            Route::post('{event}/respond', [MyEventsController::class, 'respond'])->name('.respond');
            Route::get('{event}/ics', [MyEventsController::class, 'ics'])->name('.ics');
        });

        // Rooms (ADR 0070), addressed by hashid; restore / force-delete take it
        // as a string. Literal — before the {event} wildcard.
        Route::prefix('rooms')->name('rooms.')->group(function () {
            Route::get('/', [RoomController::class, 'index'])->middleware('can:events.view')->name('index');
            Route::get('availability', [RoomController::class, 'availability'])->middleware('can:events.manage')->name('availability');
            Route::post('/', [RoomController::class, 'store'])->middleware('can:events.manage')->name('store');
            Route::post('{room}', [RoomController::class, 'update'])->middleware('can:events.manage')->name('update');
            Route::delete('{room}', [RoomController::class, 'destroy'])->middleware('can:events.manage')->name('destroy');
            Route::patch('{room}/restore', [RoomController::class, 'restore'])->middleware('can:events.manage')->name('restore');
            Route::delete('{room}/force', [RoomController::class, 'forceDelete'])->middleware('can:events.manage')->name('force-delete');
        });

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
