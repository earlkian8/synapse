<?php

namespace App\Support\Search\Sources;

use App\Models\Employee;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Employees, opened in their drawer on Employees. The list there is paged and
 * filtered, so the link searches for the employee number too — the row is
 * then on the page for the drawer to open.
 */
class EmployeeSource extends RecordSource
{
    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Employees';
    }

    protected function permission(): string
    {
        return 'employees.view';
    }

    protected function query(): Builder
    {
        return Employee::query()
            ->select(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'suffix', 'employment_status', 'department_id', 'position_id'])
            ->with(['department:id,name', 'position:id,title']);
    }

    protected function rankColumns(): array
    {
        return ['first_name', 'last_name', 'employee_no'];
    }

    protected function order(Builder $query): void
    {
        $query->orderBy('last_name')->orderBy('first_name')->orderBy('id');
    }

    /**
     * @param  Employee  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        $context = array_filter([$row->position?->title, $row->department?->name]);

        return new SearchResult(
            id: 'employee:'.$row->id,
            title: $row->full_name,
            subtitle: $context === [] ? null : implode(' · ', $context),
            hint: $row->employee_no,
            href: route('employees.index', ['search' => $row->employee_no, 'open' => $row->id], absolute: false),
        );
    }
}
