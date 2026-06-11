<?php

namespace App\Http\Resources;

use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
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
            'parent_id' => $this->parent_id,
            'head_id' => $this->head_id,
            'is_archived' => $this->trashed(),

            'head' => $this->whenLoaded('head', fn () => $this->head ? [
                'id' => $this->head->id,
                'full_name' => $this->head->full_name,
                'employee_no' => $this->head->employee_no,
                'initials' => $this->head->initials(),
            ] : null),
            'parent' => $this->whenLoaded('parent', fn () => $this->parent ? [
                'id' => $this->parent->id,
                'name' => $this->parent->name,
            ] : null),

            'employees_count' => $this->whenCounted('employees'),
            'positions_count' => $this->whenCounted('positions'),
            'children_count' => $this->whenCounted('children'),

            'positions' => $this->whenLoaded(
                'positions',
                fn () => PositionResource::collection($this->positions)->resolve($request),
            ),

            'created_human' => $this->created_at?->diffForHumans(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
