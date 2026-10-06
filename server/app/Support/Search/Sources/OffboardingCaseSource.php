<?php

namespace App\Support\Search\Sources;

use App\Models\OffboardingCase;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Offboarding cases, found by the person leaving and opened on their clearance.
 * The reason they gave is never read, let alone searched.
 */
class OffboardingCaseSource extends RecordSource
{
    public function key(): string
    {
        return 'offboarding';
    }

    public function label(): string
    {
        return 'Offboarding';
    }

    protected function permission(): string
    {
        return 'offboarding.view';
    }

    protected function query(): Builder
    {
        return OffboardingCase::query()
            ->select(['id', 'employee_id', 'type', 'status', 'last_working_day'])
            ->with('employee:id,first_name,middle_name,last_name,suffix,employee_no');
    }

    /**
     * @param  OffboardingCase  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'offboarding:'.$row->id,
            title: $row->employee->full_name,
            subtitle: Str::headline((string) $row->type)
                .($row->last_working_day ? ' · last day '.$row->last_working_day->format('M j, Y') : ''),
            hint: Str::headline((string) $row->status),
            href: route('offboarding.show', $row, absolute: false),
        );
    }
}
