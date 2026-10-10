<?php

namespace App\Http\Resources;

use App\Models\TrainingCost;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrainingCost
 */
class TrainingCostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'incurred_on' => $this->incurred_on->toDateString(),
            'recorded_by' => $this->whenLoaded('recorder', fn () => $this->recorder?->full_name),
        ];
    }
}
