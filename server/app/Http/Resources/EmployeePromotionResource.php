<?php

namespace App\Http\Resources;

use App\Models\EmployeePromotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeePromotion
 */
class EmployeePromotionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'from_position' => $this->whenLoaded('fromPosition', fn () => $this->fromPosition?->title),
            'to_position' => $this->whenLoaded('toPosition', fn () => $this->toPosition?->title),
            'from_salary' => $this->from_salary,
            'to_salary' => $this->to_salary,
            'effective_date' => $this->effective_date?->toDateString(),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
