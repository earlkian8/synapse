<?php

namespace App\Http\Resources;

use App\Models\KpiCriterion;
use App\Support\Performance\RatingScales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin KpiCriterion
 */
class KpiCriterionResource extends JsonResource
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
            'weight' => (float) $this->weight,
            'rating_scale_id' => $this->rating_scale_id,
            'scale_name' => $this->ratingScale?->name,
            'scale_descriptor' => $this->ratingScale
                ? RatingScales::descriptor($this->ratingScale->definition())
                : null,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->deleted_at !== null,
            // How many frameworks draw on this criterion.
            'usage_count' => (int) ($this->template_items_count ?? 0),
        ];
    }
}
