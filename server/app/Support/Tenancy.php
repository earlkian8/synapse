<?php

namespace App\Support;

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use Closure;

/**
 * Holds the organisation that owns the current request (the "current tenant").
 *
 * Bound as a singleton in the container and populated by
 * {@see SetCurrentOrganization} from the authenticated
 * user. The {@see OrganizationScope} reads the id from here to
 * filter every tenant-owned query; the {@see BelongsToOrganization}
 * trait reads it to stamp new records. When no tenant is set (login, registration,
 * console) scoping is a no-op — callers must set the organisation explicitly.
 */
class Tenancy
{
    protected ?Organization $organization = null;

    /**
     * Set the current organisation.
     */
    public function set(Organization $organization): void
    {
        $this->organization = $organization;
    }

    /**
     * The current organisation, if one is set.
     */
    public function organization(): ?Organization
    {
        return $this->organization;
    }

    /**
     * The current organisation's id, if one is set.
     */
    public function id(): ?int
    {
        return $this->organization?->id;
    }

    /**
     * Whether a current organisation is bound.
     */
    public function check(): bool
    {
        return $this->organization !== null;
    }

    /**
     * Clear the current organisation.
     */
    public function forget(): void
    {
        $this->organization = null;
    }

    /**
     * Run a callback with the given organisation as the current tenant,
     * restoring the previous tenant afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runFor(Organization $organization, Closure $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
