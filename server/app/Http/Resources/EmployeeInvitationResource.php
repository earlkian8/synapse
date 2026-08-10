<?php

namespace App\Http\Resources;

use App\Models\EmployeeInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An outstanding claim ticket, as HR sees it.
 *
 * `code` is exposed here on purpose — HR needs to be able to read it back to
 * somebody who never got the email. `token` is not, and cannot be: only its hash
 * is ever stored.
 *
 * @mixin EmployeeInvitation
 */
class EmployeeInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'code' => $this->code,
            'status' => $this->status(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expires_human' => $this->expires_at?->diffForHumans(),
            'created_at' => $this->created_at?->toIso8601String(),
            'invited_by' => $this->whenLoaded('inviter', fn () => $this->inviter?->full_name),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'employee_no' => $this->employee->employee_no,
                'initials' => $this->employee->initials(),
                'photo' => $this->employee->photo_url,
                'position' => $this->employee->position?->title,
                'department' => $this->employee->department?->name,
            ] : null),
        ];
    }
}
