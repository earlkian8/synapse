<?php

namespace App\Support\Search\Sources;

use App\Models\User;
use App\Support\Search\RecordSource;
use App\Support\Search\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Accounts that can sign in to this company, opened in their drawer on User
 * Management. Users are shared across companies and no global scope confines
 * them, so this source does it — exactly as the Users list does.
 */
class UserSource extends RecordSource
{
    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'Users';
    }

    protected function permission(): string
    {
        return 'users.view';
    }

    protected function query(): Builder
    {
        return User::query()
            ->inCurrentOrganization()
            ->select(['users.id', 'first_name', 'middle_name', 'last_name', 'suffix', 'email']);
    }

    protected function rankColumns(): array
    {
        return ['first_name', 'last_name', 'email'];
    }

    protected function order(Builder $query): void
    {
        $query->orderBy('last_name')->orderBy('first_name')->orderBy('users.id');
    }

    /**
     * @param  User  $row
     */
    protected function toResult(Model $row): SearchResult
    {
        return new SearchResult(
            id: 'user:'.$row->id,
            title: $row->full_name,
            subtitle: $row->email,
            hint: null,
            href: route('system.users.index', ['search' => $row->email, 'open' => $row->id], absolute: false),
        );
    }
}
