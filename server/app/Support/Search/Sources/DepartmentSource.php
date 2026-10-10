<?php

namespace App\Support\Search\Sources;

use App\Models\Department;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Departments, opened in their drawer on Departments. */
class DepartmentSource extends RecordSource
{
    public function key(): string
    {
        return 'departments';
    }

    public function label(): string
    {
        return 'Departments';
    }

    protected function permission(): string
    {
        return 'setup.departments.view';
    }

    protected function query(): Builder
    {
        return Department::query()
            ->select(['id', 'name', 'code', 'parent_id'])
            ->with('parent:id,name');
    }

    protected function rankColumns(): array
    {
        return ['name', 'code'];
    }

    protected function order(Builder $query): void
    {
        $query->orderBy('name')->orderBy('id');
    }

    /**
     * @param  Department  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'department:'.$row->id,
            title: $row->name,
            subtitle: $row->parent ? 'Under '.$row->parent->name : null,
            hint: $row->code,
            href: route('setup.departments.index', ['open' => $row->id], absolute: false),
        );
    }
}
