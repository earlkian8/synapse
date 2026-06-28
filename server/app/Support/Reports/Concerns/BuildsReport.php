<?php

namespace App\Support\Reports\Concerns;

use App\Models\Department;
use App\Support\Reports\Report;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Shared helpers for {@see Report} implementations: building
 * common filter scaffolding (department pickers, date ranges) and a sensible default
 * summary. Keeps each report focused on its own query.
 */
trait BuildsReport
{
    /**
     * A select filter listing the active organisation's departments, plus "All".
     *
     * @return array<string, mixed>
     */
    protected function departmentFilter(): array
    {
        $options = Department::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Department $department): array => [
                'value' => (string) $department->id,
                'label' => $department->name,
            ])
            ->all();

        return [
            'key' => 'department',
            'type' => 'select',
            'label' => 'Department',
            'options' => [['value' => 'all', 'label' => 'All departments'], ...$options],
            'default' => 'all',
        ];
    }

    /**
     * A select filter from a fixed value=>label map, prefixed with an "All" option.
     *
     * @param  array<string, string>  $map
     * @return array<string, mixed>
     */
    protected function selectFilter(string $key, string $label, array $map, string $allLabel = 'All'): array
    {
        $options = [['value' => 'all', 'label' => $allLabel]];

        foreach ($map as $value => $optionLabel) {
            $options[] = ['value' => (string) $value, 'label' => $optionLabel];
        }

        return [
            'key' => $key,
            'type' => 'select',
            'label' => $label,
            'options' => $options,
            'default' => 'all',
        ];
    }

    /**
     * A date-range filter defaulting to the given window (ISO date strings).
     *
     * @return array<string, mixed>
     */
    protected function dateRangeFilter(string $label, CarbonInterface $start, CarbonInterface $end): array
    {
        return [
            'key' => 'period',
            'type' => 'daterange',
            'label' => $label,
            'default' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
        ];
    }

    /**
     * A month picker (YYYY-MM) defaulting to the current month.
     *
     * @return array<string, mixed>
     */
    protected function monthFilter(string $label = 'Month'): array
    {
        return [
            'key' => 'month',
            'type' => 'month',
            'label' => $label,
            'default' => now()->format('Y-m'),
        ];
    }

    /**
     * A free-text search filter.
     *
     * @return array<string, mixed>
     */
    protected function searchFilter(string $placeholder = 'Search…'): array
    {
        return [
            'key' => 'search',
            'type' => 'search',
            'label' => 'Search',
            'placeholder' => $placeholder,
            'default' => '',
        ];
    }

    /**
     * Default summary: just the row count.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array{label: string, value: string}>
     */
    public function summary(Collection $rows, array $params): array
    {
        return [['label' => 'Total rows', 'value' => number_format($rows->count())]];
    }

    /**
     * Default: a table-only report draws no charts. Override to add decision views.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array<string, mixed>>
     */
    public function charts(Collection $rows, array $params): array
    {
        return [];
    }

    /**
     * A donut chart spec from a label=>count map (zero slices dropped).
     *
     * @param  array<string, int|float>  $counts
     * @return array<string, mixed>
     */
    protected function donut(string $title, array $counts): array
    {
        $segments = [];

        foreach ($counts as $label => $value) {
            if ($value > 0) {
                $segments[] = ['label' => $label, 'value' => (int) $value];
            }
        }

        return ['type' => 'donut', 'title' => $title, 'segments' => $segments];
    }

    /**
     * A horizontal-bar chart spec from a list of label/value pairs.
     *
     * @param  list<array{label: string, value: int|float}>  $bars
     * @return array<string, mixed>
     */
    protected function bars(string $title, array $bars): array
    {
        return ['type' => 'bars', 'title' => $title, 'bars' => $bars];
    }

    /**
     * A bar chart from a label=>count map (zero bars dropped).
     *
     * @param  array<string, int|float>  $counts
     * @return array<string, mixed>
     */
    protected function barsFromCounts(string $title, array $counts): array
    {
        $bars = [];

        foreach ($counts as $label => $value) {
            if ($value > 0) {
                $bars[] = ['label' => (string) $label, 'value' => (int) $value];
            }
        }

        return $this->bars($title, $bars);
    }
}
