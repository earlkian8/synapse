<?php

namespace App\Support\Events;

use App\Models\Event;
use Carbon\CarbonInterface;

/**
 * Events as iCalendar (RFC 5545) — the one place a VCALENDAR is written, for a
 * single event's download and for a person's subscription feed (ADR 0070).
 *
 * Each event keeps a stable `UID`, so a calendar app that subscribed replaces it
 * after an edit (a later `SEQUENCE`) and drops it once the feed no longer lists
 * it. Every line break in a text value — a lone CR included, which some readers
 * treat as one — becomes a literal "\n", so a title can never start a property
 * of its own. Long lines are folded at 75 octets, never inside a character.
 */
final class EventCalendar
{
    /**
     * @param  iterable<Event>  $events
     * @param  array<int, string>  $responses  The person's response per event id (a tentative one is marked so).
     */
    public static function document(iterable $events, ?string $name = null, array $responses = []): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Synapse//Events//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        if ($name !== null) {
            $lines[] = 'X-WR-CALNAME:'.self::escape($name);
            // Ask subscribed apps to look again every hour.
            $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
            $lines[] = 'X-PUBLISHED-TTL:PT1H';
        }

        foreach ($events as $event) {
            array_push($lines, ...self::event($event, $responses[$event->id] ?? null));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @return list<string>
     */
    private static function event(Event $event, ?string $response): array
    {
        $where = implode(', ', array_filter([$event->room?->name, $event->location]));
        $changed = $event->updated_at && $event->created_at
            ? max(0, $event->updated_at->getTimestamp() - $event->created_at->getTimestamp())
            : 0;

        return array_values(array_filter([
            'BEGIN:VEVENT',
            "UID:event-{$event->hashid}@".parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.self::utc(now()),
            // Seconds since it was made: grows with every edit, as SEQUENCE must.
            'SEQUENCE:'.min($changed, 2_000_000_000),
            $event->updated_at ? 'LAST-MODIFIED:'.self::utc($event->updated_at) : null,
            $event->starts_at ? 'DTSTART:'.self::utc($event->starts_at) : null,
            $event->ends_at ? 'DTEND:'.self::utc($event->ends_at) : null,
            'SUMMARY:'.self::escape($event->title),
            $event->description ? 'DESCRIPTION:'.self::escape($event->description) : null,
            $where !== '' ? 'LOCATION:'.self::escape($where) : null,
            'STATUS:'.($response === 'tentative' ? 'TENTATIVE' : 'CONFIRMED'),
            'URL:'.url('/events/me?event='.$event->hashid),
            'END:VEVENT',
        ], fn (?string $line): bool => $line !== null));
    }

    /** iCalendar UTC timestamp: 20260616T063000Z. Dates are immutable app-wide. */
    private static function utc(CarbonInterface $moment): string
    {
        return $moment->clone()->utc()->format('Ymd\THis\Z');
    }

    /**
     * Escape an iCalendar text value (RFC 5545 §3.3.11).
     */
    private static function escape(string $value): string
    {
        return str_replace(
            ['\\', ';', ',', "\r\n", "\n", "\r"],
            ['\\\\', '\;', '\,', '\n', '\n', '\n'],
            $value,
        );
    }

    /**
     * Fold a content line longer than 75 octets (RFC 5545 §3.1): CRLF and one
     * space before each continuation, never splitting a UTF-8 character.
     */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $parts = [];

        while (strlen($line) > 0) {
            $chunk = mb_strcut($line, 0, $parts === [] ? 75 : 74, 'UTF-8');
            $parts[] = $chunk;
            $line = substr($line, strlen($chunk));
        }

        return implode("\r\n ", $parts);
    }
}
