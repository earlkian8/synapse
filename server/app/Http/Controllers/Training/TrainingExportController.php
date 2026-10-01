<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingProgram;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the training programs as a CSV download — one row per program with its
 * provider, schedule, seat usage and completion count.
 */
class TrainingExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'training-programs-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Program', 'Provider', 'Status', 'Start', 'End',
            'Capacity', 'Enrolled', 'Completed', 'Total',
        ];

        return response()->stream(function () use ($columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            TrainingProgram::query()
                ->withCount('enrollments')
                ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
                ->withCount(['enrollments as completed_enrollments_count' => fn (Builder $q) => $q->where('status', 'completed')])
                ->recentFirst()
                ->chunk(200, function ($programs) use ($handle): void {
                    /** @var TrainingProgram $program */
                    foreach ($programs as $program) {
                        fputcsv($handle, [
                            $program->name,
                            $program->provider ?? 'In-house',
                            $program->status(),
                            $program->start_date?->toDateString(),
                            $program->end_date?->toDateString(),
                            $program->capacity ?? 'Uncapped',
                            (int) $program->active_enrollments_count,
                            (int) $program->completed_enrollments_count,
                            (int) $program->enrollments_count,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
