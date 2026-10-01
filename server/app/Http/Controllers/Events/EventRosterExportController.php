<?php

namespace App\Http\Controllers\Events;

use App\Http\Controllers\Controller;
use App\Models\Event;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one event's invitee roster as a CSV download — one row per invitee with
 * their identity, department, position, response and when they were notified.
 */
class EventRosterExportController extends Controller
{
    public function __invoke(Event $event): StreamedResponse
    {
        $filename = Str::slug($event->title).'-attendees-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position',
            'Response', 'Notified at',
        ];

        return response()->stream(function () use ($event, $columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $event->attendees()
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                ])
                ->orderBy('id')
                ->chunk(200, function ($attendees) use ($handle): void {
                    foreach ($attendees as $attendee) {
                        fputcsv($handle, [
                            $attendee->employee?->full_name,
                            $attendee->employee?->employee_no,
                            $attendee->employee?->department?->name,
                            $attendee->employee?->position?->title,
                            $attendee->response,
                            $attendee->notified_at?->format('Y-m-d H:i'),
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
