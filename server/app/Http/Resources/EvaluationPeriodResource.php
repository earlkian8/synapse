<?php

namespace App\Http\Resources;

use App\Models\EvaluationPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EvaluationPeriod
 */
class EvaluationPeriodResource extends JsonResource
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
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'is_archived' => $this->deleted_at !== null,
            'evaluations_count' => (int) ($this->evaluations_count ?? 0),
        ];
    }
}
