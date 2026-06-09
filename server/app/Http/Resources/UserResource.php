<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'middle_name' => $this->middle_name,
            'last_name' => $this->last_name,
            'suffix' => $this->suffix,
            'full_name' => $this->full_name,
            'initials' => $this->initials(),
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'profile_photo' => $this->profile_photo,
            'employee_id' => $this->employee_id,
            'is_active' => (bool) $this->is_active,
            'status' => $this->resolveStatus(),
            'email_verified' => $this->email_verified_at !== null,
            'two_factor_enabled' => $this->two_factor_secret !== null,
            'has_password' => $this->password !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_human' => $this->last_login_at?->diffForHumans(),
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }

    /**
     * Resolve a single high-level status for the user.
     */
    private function resolveStatus(): string
    {
        if ($this->trashed()) {
            return 'archived';
        }

        return $this->is_active ? 'active' : 'inactive';
    }

    /**
     * Build the user's initials from their first and last name.
     */
    private function initials(): string
    {
        return mb_strtoupper(
            mb_substr((string) $this->first_name, 0, 1).mb_substr((string) $this->last_name, 0, 1)
        );
    }
}
