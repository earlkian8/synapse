<?php

namespace App\Models\Scopes;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Confines every query on a tenant-owned model to the current organisation.
 *
 * Applied automatically by {@see BelongsToOrganization}. When
 * no organisation is bound (login, registration, console) the scope is a no-op, so
 * code running outside a request must set the tenant explicitly. Because isolation
 * lives at the query layer, it holds regardless of a user's permissions — even an
 * organisation's super-admin can never reach another tenant's rows.
 */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenancy = app(Tenancy::class);

        if ($tenancy->check()) {
            $builder->where(
                $model->qualifyColumn('organization_id'),
                $tenancy->id(),
            );
        }
    }
}
