<?php

namespace App\Http\Resources\Admin;

use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An admin account as the portal shows it. Never the password hash or the
 * authenticator secret — only whether one is enrolled.
 *
 * @mixin Admin
 */
class AdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role,
            'role_label' => $this->roleLabel(),
            'is_active' => $this->is_active,
            'two_factor_enabled' => $this->hasTwoFactor(),
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'last_login_ip' => $this->last_login_ip,
            'password_changed_at' => $this->password_changed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'is_self' => $request->user('admin')?->getAuthIdentifier() === $this->id,
        ];
    }
}
