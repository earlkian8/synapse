<?php

namespace App\Http\Controllers\ActivityLog;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Queries\ActivityLogsIndexQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityLogExportController extends Controller
{
    /**
     * Export the currently filtered activity logs as a CSV download.
     */
    public function __invoke(Request $request, ActivityLogsIndexQuery $query): StreamedResponse
    {
        $filename = 'activity-logs-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = ['Date', 'Event', 'Description', 'Actor', 'Subject', 'IP Address'];

        return response()->stream(function () use ($query, $request, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($logs) use ($handle) {
                /** @var ActivityLog $log */
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->created_at?->toDateTimeString(),
                        $log->event,
                        $log->description,
                        $log->causer?->full_name ?? 'System',
                        $log->subject_label,
                        $log->ip_address,
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
