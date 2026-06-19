<?php

namespace App\Http\Resources;

use App\Models\ClearanceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClearanceItem
 */
class ClearanceItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item' => $this->item,
            'status' => $this->status,
            'is_cleared' => $this->isCleared(),
            'remarks' => $this->remarks,

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'cleared_by' => $this->whenLoaded('clearedBy', fn () => $this->clearedBy?->full_name),

            'department_id' => $this->department_id,
            'cleared_at' => $this->cleared_at?->toIso8601String(),
            'cleared_human' => $this->cleared_at?->diffForHumans(),
            'sort_order' => $this->sort_order,
        ];
    }
}
