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
        $jobPosting->loadMissing('pipeline.stages');

        $slug = Str::slug($jobPosting->title) ?: 'pipeline';
        $filename = "{$slug}-pipeline-".now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Candidate', 'Email', 'Phone', 'Headline', 'Experience (yrs)', 'Source',
            'Stage', 'Fit', 'Fit band', 'Skills shown', 'Skills missing', 'Rating', 'Recommendation', 'Applied', 'Age (days)',
        ];

        return response()->stream(function () use ($jobPosting, $scorer, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $jobPosting->applications()
                ->with([
                    'applicant' => fn ($q) => $q->withCount('documents'),
                    'interviews:id,job_application_id,result',
                    'pipelineStage',
                ])
                ->chunk(200, function ($applications) use ($handle, $scorer, $jobPosting) {
                    /** @var JobApplication $application */
                    foreach ($applications as $application) {
                        $application->setRelation('jobPosting', $jobPosting);
                        $applicant = $application->applicant;

                        $fit = $jobPosting->use_fit_scoring ? $scorer->score($application, $jobPosting) : null;
                        $recommendation = $fit ? $scorer->recommendation($application, $fit) : null;
                        $skills = collect($fit['breakdown'] ?? [])->firstWhere('key', 'skills')['skills'] ?? [];

                        fputcsv($handle, [
                            $applicant?->full_name,
                            $applicant?->email,
                            $applicant?->phone,
                            $applicant?->headline,
                            $applicant?->years_experience,
                            $applicant?->source,
                            $application->pipelineStage->name,
                            $fit['value'] ?? null,
                            $fit['band'] ?? null,
                            $this->skillNames($skills, shown: true),
                            $this->skillNames($skills, shown: false),
                            $application->rating,
                            $recommendation['label'] ?? null,
                            $application->applied_at?->toDateString(),
                            $application->applied_at ? (int) $application->applied_at->diffInDays() : null,
                        ]);
                    }
                });

            fclose($handle);
        }, 200, $headers);
    }

    /**
     * The posting's skills a candidate shows (fully, or partly with what
     * stood in: "Vue (related: React)"), or the ones they don't.
     *
     * @param  list<array{skill: string, status: string, via: string|null, credit: float}>  $skills
     */
    private function skillNames(array $skills, bool $shown): ?string
    {
        $names = [];

        foreach ($skills as $skill) {
            if (($skill['credit'] > 0) !== $shown) {
                continue;
            }

            $names[] = $shown && $skill['credit'] < 1
                ? "{$skill['skill']} (related: {$skill['via']})"
                : $skill['skill'];
        }

        return $names === [] ? null : implode('; ', $names);
    }
}
