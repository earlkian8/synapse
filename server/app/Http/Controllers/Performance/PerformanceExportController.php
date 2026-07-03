<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\PerformanceEvaluation;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the performance evaluations as a CSV download — one row per appraisal
 * with the employee, period, weighted overall (1–5), status and key dates.
 */
class PerformanceExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'performance-evaluations-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position', 'Period',
            'Status', 'Overall (1-5)', 'Submitted', 'Acknowledged',
        ];

        return response()->stream(function () use ($columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            PerformanceEvaluation::query()
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                    'period:id,name',
                ])
                ->latestFirst()
                ->chunk(200, function ($evaluations) use ($handle): void {
                    /** @var PerformanceEvaluation $evaluation */
                    foreach ($evaluations as $evaluation) {
                        fputcsv($handle, [
                            $evaluation->employee?->full_name,
                            $evaluation->employee?->employee_no,
                            $evaluation->employee?->department?->name,
                            $evaluation->employee?->position?->title,
                            $evaluation->period?->name,
                            $evaluation->status,
                            $evaluation->overall_score === null ? '' : number_format((float) $evaluation->overall_score, 2),
                            $evaluation->submitted_at?->toDateString(),
                            $evaluation->acknowledged_at?->toDateString(),
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
