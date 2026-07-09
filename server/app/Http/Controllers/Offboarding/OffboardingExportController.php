<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Models\OffboardingCase;
use App\Queries\OffboardingCasesIndexQuery;
use App\Support\OffboardingProvisioner;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the offboarding board as a CSV download — one row per exit with the
 * employee, the exit kind, key dates and the clearance tallies. Reuses the index
 * query, so the export honours whatever filters the board currently shows.
 */
class OffboardingExportController extends Controller
{
    public function __invoke(Request $request, OffboardingCasesIndexQuery $query): StreamedResponse
    {
        $filename = 'offboarding-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position',
            'Exit type', 'Status', 'Notice date', 'Last working day',
            'Clearance', 'Cleared', 'Flagged', 'Total items', 'Completed at',
        ];

        return response()->stream(function () use ($request, $query, $columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($cases) use ($handle): void {
                /** @var OffboardingCase $case */
                foreach ($cases as $case) {
                    $total = (int) $case->items_count;
                    $cleared = (int) $case->cleared_items_count;

                    fputcsv($handle, [
                        $case->employee?->full_name,
                        $case->employee?->employee_no,
                        $case->employee?->department?->name,
                        $case->employee?->position?->title,
                        $case->type,
                        $case->status,
                        $case->notice_date?->toDateString(),
                        $case->last_working_day?->toDateString(),
                        OffboardingProvisioner::clearanceStatus($total, $cleared),
                        $cleared,
                        (int) $case->flagged_items_count,
                        $total,
                        $case->completed_at?->format('Y-m-d H:i'),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
