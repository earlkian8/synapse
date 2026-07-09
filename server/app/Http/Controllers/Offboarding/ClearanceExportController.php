<?php

namespace App\Http\Controllers\Offboarding;

use App\Http\Controllers\Controller;
use App\Models\OffboardingCase;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one exit's clearance sheet as a CSV download — one row per sign-off with
 * its owning department, status, remarks and who cleared it when. The digital
 * stand-in for the classic printed clearance form that gets walked around.
 */
class ClearanceExportController extends Controller
{
    public function __invoke(OffboardingCase $case): StreamedResponse
    {
        $case->load('employee:id,first_name,middle_name,last_name,suffix');

        $slug = Str::slug($case->employee?->full_name ?? 'employee');
        $filename = "clearance-{$slug}-".now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Item', 'Department', 'Status', 'Remarks', 'Cleared by', 'Cleared at',
        ];

        return response()->stream(function () use ($case, $columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $items = $case->clearanceItems()
                ->with([
                    'department:id,name',
                    'clearedBy:id,first_name,last_name',
                ])
                ->get();

            foreach ($items as $item) {
                fputcsv($handle, [
                    $item->item,
                    $item->department?->name ?? 'Unassigned',
                    $item->status,
                    $item->remarks,
                    $item->clearedBy ? trim($item->clearedBy->first_name.' '.$item->clearedBy->last_name) : null,
                    $item->cleared_at?->format('Y-m-d H:i'),
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
