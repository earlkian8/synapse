<?php

namespace App\Http\Resources;

use App\Models\OffboardingProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OffboardingProgram
 */
class OffboardingProgramResource extends JsonResource
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
            'description' => $this->description,
            'exit_type' => $this->exit_type,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'department_id' => $this->department_id,

            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'item' => $item->item,
                'department_id' => $item->department_id,
                'department' => $item->department?->name,
                'use_employee_department' => $item->use_employee_department,
                'sort_order' => $item->sort_order,
            ])->values()),
            'items_count' => $this->whenCounted('items'),
            'cases_count' => $this->whenCounted('cases'),

            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
