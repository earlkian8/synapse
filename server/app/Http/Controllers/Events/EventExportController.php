<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the events overview as a CSV download — one row per event with its kind,
 * derived status, schedule, location, organizer and attendance tallies.
 */
class EventExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'events-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Title', 'Type', 'Status', 'Starts', 'Ends',
            'Location', 'Organizer', 'Invited', 'Attending',
        ];

        return response()->stream(function () use ($columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            Event::query()
                ->with('organizer:id,first_name,last_name')
                ->withCount('attendees')
                ->withCount(['attendees as attending_count' => fn (Builder $q) => $q->attending()])
                ->chronological()
                ->chunk(200, function ($events) use ($handle): void {
                    /** @var Event $event */
                    foreach ($events as $event) {
                        fputcsv($handle, [
                            $event->title,
                            $event->type,
                            $event->status(),
                            $event->starts_at?->format('Y-m-d H:i'),
                            $event->ends_at?->format('Y-m-d H:i'),
                            $event->location,
                            $event->organizer ? trim($event->organizer->first_name.' '.$event->organizer->last_name) : null,
                            (int) $event->attendees_count,
                            (int) $event->attending_count,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
