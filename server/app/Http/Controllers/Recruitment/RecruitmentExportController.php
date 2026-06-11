<?php

namespace App\Http\Controllers\Recruitment;

use App\Http\Controllers\Controller;
use App\Models\JobPosting;
use App\Queries\JobPostingsIndexQuery;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecruitmentExportController extends Controller
{
    /**
     * Export the currently filtered job postings as a CSV download.
     */
    public function __invoke(Request $request, JobPostingsIndexQuery $query): StreamedResponse
    {
        $filename = 'job-postings-'.now()->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $columns = [
            'Title', 'Department', 'Position', 'Employment Type', 'Openings',
            'Status', 'Applications', 'Hired', 'Closing Date', 'Posted',
        ];

        return response()->stream(function () use ($query, $request, $columns) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $columns);

            $query->build($request)->chunk(200, function ($postings) use ($handle) {
                /** @var JobPosting $posting */
                foreach ($postings as $posting) {
                    fputcsv($handle, [
                        $posting->title,
                        $posting->department?->name,
                        $posting->position?->title,
                        $posting->employment_type,
                        $posting->openings,
                        $posting->status,
                        $posting->applications_count,
                        $posting->hired_count,
                        $posting->closing_date?->toDateString(),
                        $posting->created_at?->toDateString(),
                    ]);
                }
            });

            fclose($handle);
        }, 200, $headers);
    }
}
