<?php

namespace App\Support\Search\Sources;

use App\Models\JobPosting;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Job postings, opened on their hiring board. */
class JobPostingSource extends RecordSource
{
    public function key(): string
    {
        return 'job-postings';
    }

    public function label(): string
    {
        return 'Job postings';
    }

    protected function permission(): string
    {
        return 'recruitment.view';
    }

    protected function query(): Builder
    {
        return JobPosting::query()
            ->select(['id', 'title', 'status', 'department_id'])
            ->with('department:id,name');
    }

    protected function rankColumns(): array
    {
        return ['title'];
    }

    /**
     * @param  JobPosting  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'job-posting:'.$row->id,
            title: $row->title,
            subtitle: $row->department?->name,
            hint: Str::headline((string) $row->status),
            href: route('recruitment.show', $row, absolute: false),
        );
    }
}
