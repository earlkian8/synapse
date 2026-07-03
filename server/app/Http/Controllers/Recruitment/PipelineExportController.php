<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\JobApplication;
use App\Models\JobPosting;
use App\Support\Recruitment\ApplicantScorer;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PipelineExportController extends Controller
{
    /**
     * Export a posting's hiring pipeline — every candidate with their stage,
     * fit score, rating and recommended next step — as a CSV download.
     */
    public function __invoke(JobPosting $jobPosting, ApplicantScorer $scorer): StreamedResponse
    {
        $slug = Str::slug($jobPosting->title) ?: 'pipeline';
        $filename = "{$slug}-pipeline-".now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Candidate', 'Email', 'Phone', 'Headline', 'Experience (yrs)', 'Source',
            'Stage', 'Fit', 'Fit band', 'Rating', 'Recommendation', 'Applied', 'Age (days)',
        ];

        return response()->stream(function () use ($jobPosting, $scorer, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $jobPosting->applications()
                ->with(['applicant' => fn ($q) => $q->withCount('documents'), 'interviews:id,job_application_id,result'])
                ->chunk(200, function ($applications) use ($handle, $scorer, $jobPosting) {
                    /** @var JobApplication $application */
                    foreach ($applications as $application) {
                        $fit = $scorer->score($application, $jobPosting);
                        $recommendation = $scorer->recommendation($application, $fit);
                        $applicant = $application->applicant;

                        fputcsv($handle, [
                            $applicant?->full_name,
                            $applicant?->email,
                            $applicant?->phone,
                            $applicant?->headline,
                            $applicant?->years_experience,
                            $applicant?->source,
                            $application->stage,
                            $fit['value'],
                            $fit['band'],
                            $application->rating,
                            $recommendation['label'],
                            $application->applied_at?->toDateString(),
                            $application->applied_at ? (int) $application->applied_at->diffInDays() : null,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }
}
