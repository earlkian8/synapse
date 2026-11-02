<?php

namespace App\Support\Reports\Concerns;

use App\Models\Employee;
use App\Support\Reports\Reports\AttritionRiskReport;
use App\Support\Reports\Reports\PerformanceForecastReport;
use App\Support\Reports\Reports\PromotionReadinessReport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the three predictive reports share ({@see AttritionRiskReport},
 * {@see PromotionReadinessReport}, {@see PerformanceForecastReport}): each reads the
 * scores of its surface's **latest completed run** — the workforce summary the
 * analytics pages used to draw (ADR 0099) — narrowed by department, tier or band,
 * and a search on the person, with the same four columns naming each person.
 */
trait ReadsPredictionRun
{
    /**
     * The latest completed run of a surface, or null before the first one.
     *
     * @template TRun of Model
     *
     * @param  class-string<TRun>  $runClass
     * @return TRun|null
     */
    protected function latestRun(string $runClass): ?Model
    {
        return $runClass::query()->where('status', 'completed')->latest('id')->first();
    }

    /**
     * Narrow a run's scores to the report's filters.
     *
     * @param  Builder<Model>  $query
     * @param  array<string, mixed>  $params
     */
    protected function applyPersonFilters(Builder $query, array $params, string $tierColumn, string $tierParam): void
    {
        $query->with(['employee:id,first_name,middle_name,last_name,suffix,employee_no,department_id,position_id', 'employee.department:id,name', 'employee.position:id,title']);

        if (($params['department'] ?? 'all') !== 'all') {
            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', (int) $params['department']));
        }

        if (($params[$tierParam] ?? 'all') !== 'all') {
            $query->where($tierColumn, $params[$tierParam]);
        }

        if (($params['search'] ?? '') !== '') {
            $query->whereHas('employee', fn (Builder $q) => $q->search($params['search']));
        }
    }

    /**
     * The columns naming each person, in display order.
     *
     * @return list<array<string, string>>
     */
    protected function personColumns(): array
    {
        return [
            ['key' => 'employee_no', 'label' => 'Employee No', 'align' => 'left', 'type' => 'text'],
            ['key' => 'full_name', 'label' => 'Name', 'align' => 'left', 'type' => 'text'],
            ['key' => 'department', 'label' => 'Department', 'align' => 'left', 'type' => 'text'],
            ['key' => 'position', 'label' => 'Position', 'align' => 'left', 'type' => 'text'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function personCells(?Employee $employee): array
    {
        return [
            'employee_no' => $employee?->employee_no ?? '—',
            'full_name' => $employee?->full_name ?? 'Former employee',
            'department' => $employee?->department?->name ?? 'No department',
            'position' => $employee?->position?->title ?? '—',
        ];
    }

    /**
     * A signed number of points, as the analytics pages write them ("+14", "−3").
     */
    protected function points(float $points): string
    {
        $rounded = (int) round(abs($points));

        return $rounded === 0 ? '±0' : ($points >= 0 ? '+' : '−').$rounded;
    }
}
