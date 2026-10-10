<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingEnrollment;
use App\Models\TrainingProgram;
use App\Support\Training\TrainingSessions;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one training program's roster as a CSV download — a row per enrolled
 * employee with their status, completion score, dates, attendance, certificate
 * and remarks. Requests to join are not on the roster.
 */
class TrainingRosterExportController extends Controller
{
    public function __invoke(TrainingProgram $trainingProgram): StreamedResponse
    {
        $slug = Str::slug($trainingProgram->name) ?: 'program';
        $filename = "training-{$slug}-roster-".now()->format('Y-m-d').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Employee', 'Employee No.', 'Department', 'Position',
            'Status', 'Score', 'Enrolled', 'Completed', 'Attendance', 'Certificate No.', 'Remarks',
        ];

        $rates = TrainingSessions::rates($trainingProgram);

        return response()->stream(function () use ($columns, $trainingProgram, $rates): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $trainingProgram->enrollments()
                ->onRoster()
                ->with([
                    'certificate:id,training_enrollment_id,certificate_no',
                    'employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id',
                    'employee.department:id,name',
                    'employee.position:id,title',
                ])
                ->orderBy('id')
                ->chunk(200, function ($enrollments) use ($handle, $rates): void {
                    /** @var TrainingEnrollment $enrollment */
                    foreach ($enrollments as $enrollment) {
                        fputcsv($handle, [
                            $enrollment->employee?->full_name,
                            $enrollment->employee?->employee_no,
                            $enrollment->employee?->department?->name,
                            $enrollment->employee?->position?->title,
                            $enrollment->status,
                            $enrollment->score === null ? '' : number_format((float) $enrollment->score, 2),
                            $enrollment->created_at?->toDateString(),
                            $enrollment->completed_at?->toDateString(),
                            isset($rates[$enrollment->id]['rate']) ? "{$rates[$enrollment->id]['attended']} of {$rates[$enrollment->id]['counted']} ({$rates[$enrollment->id]['rate']}%)" : '',
                            $enrollment->certificate?->certificate_no,
                            $enrollment->remarks,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
