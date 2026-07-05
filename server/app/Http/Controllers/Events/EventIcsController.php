<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Downloads a single event as an iCalendar (.ics) file, so anyone can add it to
 * their own calendar (Outlook, Google, Apple). An event with no end is emitted as
 * a point in time (DTSTART only).
 */
class EventIcsController extends Controller
{
    public function __invoke(Event $event): Response
    {
        $lines = array_filter([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Synapse//Events//EN',
            'BEGIN:VEVENT',
            "UID:event-{$event->hashid}@".parse_url(config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.$this->utc(now()),
            $event->starts_at ? 'DTSTART:'.$this->utc($event->starts_at) : null,
            $event->ends_at ? 'DTEND:'.$this->utc($event->ends_at) : null,
            'SUMMARY:'.$this->escape($event->title),
            $event->description ? 'DESCRIPTION:'.$this->escape($event->description) : null,
            $event->location ? 'LOCATION:'.$this->escape($event->location) : null,
            'END:VEVENT',
            'END:VCALENDAR',
        ], fn (?string $line): bool => $line !== null);

        return response(implode("\r\n", $lines)."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.Str::slug($event->title).'.ics"',
        ]);
    }

    /** iCalendar UTC timestamp: 20260616T063000Z. */
    private function utc(Carbon $moment): string
    {
        return $moment->clone()->utc()->format('Ymd\THis\Z');
    }

    /** Escape iCalendar text values (RFC 5545 §3.3.11). */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            $value,
        );
    }
}
