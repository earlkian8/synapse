<?php

namespace App\Support\Search\Sources;

use App\Models\Applicant;
use App\Models\JobApplication;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Applicants, opened in their latest application's drawer on that posting's
 * board — an applicant is only ever looked at through an application, so one
 * who has none is not offered.
 */
class ApplicantSource extends RecordSource
{
    public function key(): string
    {
        return 'applicants';
    }

    public function label(): string
    {
        return 'Applicants';
    }

    protected function permission(): string
    {
        return 'recruitment.view';
    }

    protected function query(): Builder
    {
        return Applicant::query()
            ->select(['id', 'first_name', 'last_name', 'headline'])
            ->whereHas('applications')
            ->with([
                'applications' => fn ($query) => $query
                    ->select(['id', 'applicant_id', 'job_posting_id', 'applied_at'])
                    ->latest('applied_at')
                    ->latest('id'),
                'applications.jobPosting:id,title',
            ]);
    }

    protected function rankColumns(): array
    {
        return ['first_name', 'last_name'];
    }

    protected function order(Builder $query): void
    {
        $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }

    /**
     * @param  Applicant  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        /** @var JobApplication $application */
        $application = $row->applications->first();

        return new SearchResult(
            id: 'applicant:'.$row->id,
            title: $row->full_name,
            subtitle: 'Applied for '.$application->jobPosting->title,
            hint: $row->headline,
            href: route('recruitment.show', ['jobPosting' => $application->jobPosting, 'open' => $application->id], absolute: false),
        );
    }
}
