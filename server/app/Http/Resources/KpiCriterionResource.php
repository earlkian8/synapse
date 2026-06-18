<?php

namespace App\Http\Resources;

use App\Models\KpiCriterion;
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
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->deleted_at !== null,
            'usage_count' => (int) ($this->scores_count ?? 0),
        ];
    }
}
