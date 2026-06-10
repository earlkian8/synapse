<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Marks a model as tenant-owned: scoped to the current organisation on read and
 * stamped with it on write.
 *
 * Adds the {@see OrganizationScope} global scope (so every query is confined to the
 * current tenant) and a `creating` hook that fills `organization_id` from the
 * current tenant when it has not been set explicitly. See ADR 0005.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('organization_id') === null) {
                $model->setAttribute('organization_id', app(Tenancy::class)->id());
            }
        });
    }

    /**
     * The organisation this record belongs to.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
