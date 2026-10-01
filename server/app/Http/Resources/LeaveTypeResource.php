<?php

namespace App\Http\Resources;

use App\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'color' => $this->color,
            'default_days' => (float) $this->default_days,
            'is_paid' => $this->is_paid,
            'allow_half_day' => $this->allow_half_day,
            'requires_approval' => $this->requires_approval,
            'is_active' => $this->is_active,
            'is_archived' => $this->trashed(),

            'requests_count' => $this->whenCounted('requests'),

            'created_human' => $this->created_at?->diffForHumans(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
