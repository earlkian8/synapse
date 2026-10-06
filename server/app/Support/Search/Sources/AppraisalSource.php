<?php

namespace App\Support\Search\Sources;

use App\Models\PerformanceEvaluation;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Appraisals, found by the employee and the cycle together ("maria q3") and
 * opened on their scorecard. Scores are not shown in the palette.
 */
class AppraisalSource extends RecordSource
{
    public function key(): string
    {
        return 'appraisals';
    }

    public function label(): string
    {
        return 'Appraisals';
    }

    protected function permission(): string
    {
        return 'performance.view';
    }

    protected function query(): Builder
    {
        return PerformanceEvaluation::query()
            ->select(['id', 'employee_id', 'evaluation_period_id', 'status'])
            // An archived employee's appraisals stay behind (archiving does not
            // cascade), but the employee is not shown anywhere — so neither are they.
            ->whereHas('employee')
            ->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no', 'period:id,name']);
    }

    /**
     * @param  PerformanceEvaluation  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'appraisal:'.$row->id,
            title: $row->employee->full_name,
            subtitle: ($row->period?->name ?? 'Appraisal').' appraisal',
            hint: Str::headline((string) $row->status),
            href: route('performance.show', $row, absolute: false),
        );
    }
}
