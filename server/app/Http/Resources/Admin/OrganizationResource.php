<?php

namespace App\Http\Resources\Admin;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organisation as the admin portal lists it (ADR 0085). Its profile and its
 * measures — never the join code, which is a credential, nor its employer
 * registration numbers, which the portal has no use for.
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'slug' => $this->slug,
            'email' => $this->email,
            'logo_url' => $this->logo_url,
            'initials' => $this->initials(),
            'timezone' => $this->timezone,
            'setup_completed_at' => $this->setup_completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived_at' => $this->deleted_at?->toIso8601String(),
            'members_count' => (int) ($this->members_count ?? 0),
            'employees_count' => (int) ($this->employees_count ?? 0),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
        ];
    }
}
