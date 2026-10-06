<?php

namespace App\Support\Search\Sources;

use App\Models\Role;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Roles, opened in their drawer on Roles & Permissions. */
class RoleSource extends RecordSource
{
    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return 'Roles';
    }

    protected function permission(): string
    {
        return 'roles.view';
    }

    protected function query(): Builder
    {
        return Role::query()->select(['id', 'name', 'label', 'description', 'is_system']);
    }

    protected function rankColumns(): array
    {
        return ['name', 'label'];
    }

    protected function order(Builder $query): void
    {
        $query->orderBy('label')->orderBy('id');
    }

    /**
     * @param  Role  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'role:'.$row->id,
            title: $row->label ?: $row->name,
            subtitle: $row->description ? Str::limit($row->description, 80) : null,
            hint: $row->is_system ? 'System' : null,
            href: route('system.roles.index', ['search' => $row->name, 'open' => $row->id], absolute: false),
        );
    }
}
