<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\Events\EventCalendar;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Downloads a single event as an iCalendar (.ics) file, so anyone can add it to
 * their own calendar (Outlook, Google, Apple). An event with no end is emitted as
 * a point in time (DTSTART only). {@see EventCalendar} writes it, as it writes
 * every invitee's subscription feed.
 */
class EventIcsController extends Controller
{
    public function __invoke(Event $event): Response
    {
        return self::download($event);
    }

    /**
     * The file response, for this screen and an invitee's own download.
     */
    public static function download(Event $event): Response
    {
        $event->loadMissing('room:id,name');

        return response(EventCalendar::document([$event]), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.(Str::slug($event->title) ?: 'event').'.ics"',
        ]);
    }
}
