<?php

namespace App\Http\Controllers\Awards;

use App\Http\Controllers\Controller;
use App\Models\EmployeeAward;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the recognition feed as a CSV download — one row per award given, with
 * the recipient, the award type, the date, the reason and the granter.
 */
class AwardExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'awards-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position',
            'Award', 'Awarded on', 'Reason', 'Granted by',
        ];

        return response()->stream(function () use ($columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            EmployeeAward::query()
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                    'awardType:id,name',
                    'grantedBy:id,first_name,last_name',
                ])
                ->latestFirst()
                ->chunk(200, function ($awards) use ($handle): void {
                    /** @var EmployeeAward $award */
                    foreach ($awards as $award) {
                        fputcsv($handle, [
                            $award->employee?->full_name,
                            $award->employee?->employee_no,
                            $award->employee?->department?->name,
                            $award->employee?->position?->title,
                            $award->awardType?->name,
                            $award->awarded_on?->toDateString(),
                            $award->reason,
                            $award->grantedBy ? trim($award->grantedBy->first_name.' '.$award->grantedBy->last_name) : null,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
