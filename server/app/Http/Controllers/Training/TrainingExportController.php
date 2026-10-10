<?php

namespace App\Http\Controllers\Training;

use App\Http\Controllers\Controller;
use App\Models\TrainingProgram;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the training programs as a CSV download — one row per program with its
 * provider, schedule, seat usage and completion count, who may join it, the
 * certificate it issues, its sessions, and its budget against what it spent.
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
            'Who can join', 'Certificate', 'Sessions', 'Budget', 'Spent',
        ];

        return response()->stream(function () use ($columns): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            TrainingProgram::query()
                ->withCount(['enrollments' => fn (Builder $q) => $q->onRoster()])
                ->withCount(['enrollments as active_enrollments_count' => fn (Builder $q) => $q->active()])
                ->withCount('sessions')
                ->withSum('costs as spent_total', 'amount')
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
                            match ($program->enrollment_mode) {
                                'open' => 'Anyone',
                                'approval' => 'On approval',
                                default => 'HR only',
                            },
                            $program->issues_certificate
                                ? ($program->certificate_validity_months ? "Valid {$program->certificate_validity_months} months" : 'No expiry')
                                : '',
                            (int) $program->sessions_count,
                            $program->budget === null ? '' : number_format((float) $program->budget, 2, '.', ''),
                            number_format((float) ($program->spent_total ?? 0), 2, '.', ''),
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
