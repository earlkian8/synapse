<?php

namespace App\Support\Search\Sources;

use App\Models\OnboardingCase;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Onboarding cases, found by the new hire and opened on their checklist. */
class OnboardingCaseSource extends RecordSource
{
    public function key(): string
    {
        return 'onboarding';
    }

    public function label(): string
    {
        return 'Onboarding';
    }

    protected function permission(): string
    {
        return 'onboarding.view';
    }

    protected function query(): Builder
    {
        return OnboardingCase::query()
            ->select(['id', 'employee_id', 'status', 'start_date'])
            ->with('employee:id,first_name,middle_name,last_name,suffix,employee_no');
    }

    /**
     * @param  OnboardingCase  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'onboarding:'.$row->id,
            title: $row->employee->full_name,
            subtitle: 'Onboarding from '.$row->start_date->format('M j, Y'),
            hint: Str::headline((string) $row->status),
            href: route('onboarding.show', $row, absolute: false),
        );
    }
}
