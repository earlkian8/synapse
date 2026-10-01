<?php

namespace App\Http\Resources;

use App\Models\RecruitmentPipeline;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RecruitmentPipeline
 */
class RecruitmentPipelineResource extends JsonResource
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
            'is_default' => $this->is_default,

            'stages' => $this->whenLoaded('stages', fn () => $this->stages->map(fn ($stage) => [
                'id' => $stage->id,
                'name' => $stage->name,
                'kind' => $stage->kind,
                'position' => $stage->position,
            ])->values()),

            'postings_count' => $this->whenCounted('postings'),

            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
