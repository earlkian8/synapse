<?php

namespace App\Http\Resources;

use App\Models\PerformanceScore;
use App\Support\Performance\RatingScales;
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
        $scale = $this->scale();

        return [
            'id' => $this->id,
            'label' => $this->label,
            'description' => $this->description,
            'weight' => (float) $this->weight,
            'score' => $this->score === null ? null : (float) $this->score,
            'remarks' => $this->remarks,
            'sort_order' => (int) $this->sort_order,

            // The section this line was measured in (snapshot).
            'section_key' => $this->section_key ?? 'overall',
            'section_name' => $this->section_name,
            'section_weight' => (float) $this->section_weight,

            // The line's own rating scale (snapshot at scoring time).
            'scale_type' => $scale['type'],
            'scale_name' => $this->scale_name,
            'scale_min' => $scale['min'],
            'scale_max' => $scale['max'],
            'scale_step' => $scale['step'],
            'scale_levels' => $scale['levels'],
            'scale_descriptor' => RatingScales::descriptor($scale),

            // Whether the criterion still exists (not archived/removed).
            'criterion_active' => $this->relationLoaded('criterion')
                ? ($this->criterion?->is_active ?? false)
                : null,
        ];
    }
}
