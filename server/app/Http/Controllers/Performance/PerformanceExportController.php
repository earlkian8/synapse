<?php

namespace App\Http\Controllers\Performance;

use App\Http\Controllers\Controller;
use App\Models\PerformanceEvaluation;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the appraisals as a CSV download — one row each, reported the way the
 * company reports them: the framework it was conducted under, attainment on
 * 0–100, and the **rating label** its own model gave it. The 1–5 projection is
 * carried alongside so a spreadsheet can still be compared across frameworks.
 *
 * Honours the cycle the overview is currently on, so an export is the list that
 * was on screen.
 */
class PerformanceExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $periodId = $request->integer('period') ?: null;
        $filename = 'performance-appraisals-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position', 'Review cycle',
            'Framework', 'Status', 'Rating', 'Attainment (%)', 'Overall (1-5)',
            'Submitted', 'Acknowledged',
        ];

        return response()->stream(function () use ($columns, $periodId): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            PerformanceEvaluation::query()
                ->with([
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                    'period:id,name',
                ])
                ->forPeriod($periodId)
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
                            $evaluation->template_name,
                            $evaluation->status,
                            $evaluation->result_label,
                            $evaluation->overall_percent === null ? '' : number_format((float) $evaluation->overall_percent, 2),
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
