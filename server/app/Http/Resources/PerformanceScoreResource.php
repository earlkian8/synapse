<?php

namespace App\Http\Resources;

use App\Models\PerformanceScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PerformanceScore
 */
class PerformanceScoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'weight' => (float) $this->weight,
            'score' => $this->score === null ? null : (float) $this->score,
            // The line's own rating scale (snapshot at scoring time).
            'scale_type' => $this->scale_type ?? 'points',
            'scale_min' => (float) ($this->scale_min ?? 1),
            'scale_max' => (float) ($this->scale_max ?? 5),
            'scale_levels' => $this->scale_levels,
            'remarks' => $this->remarks,
            // Whether the criterion still exists (not archived/removed).
            'criterion_active' => $this->relationLoaded('criterion')
                ? ($this->criterion?->is_active ?? false)
                : null,
        ];
    }
}
