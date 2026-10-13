<?php

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An ERP sign-in account as the admin portal lists it (ADR 0085): who it is and
 * how it signs in, not anything from the HR record behind it.
 *
 * @mixin User
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'is_active' => (bool) $this->is_active,
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'two_factor_enabled' => $this->two_factor_confirmed_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'archived_at' => $this->deleted_at?->toIso8601String(),
            'memberships_count' => (int) ($this->memberships_count ?? 0),
        ];
    }
}
