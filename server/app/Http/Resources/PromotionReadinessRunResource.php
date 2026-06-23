<?php

namespace App\Http\Resources;

use App\Models\PromotionReadinessRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PromotionReadinessRun
 */
class PromotionReadinessRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'status' => $this->status,
            'model_version' => $this->model_version,
            'employees_scored' => (int) $this->employees_scored,
            'high_count' => (int) $this->high_count,
            'medium_count' => (int) $this->medium_count,
            'low_count' => (int) $this->low_count,
            'average_score' => $this->average_score === null ? null : (float) $this->average_score,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator
                ? trim("{$this->generator->first_name} {$this->generator->last_name}")
                : null),
            'created_at' => $this->created_at?->toIso8601String(),

            // Resolve to a plain array so Inertia sends a JSON list, not a
            // `{ data: [...] }`-wrapped object (mirrors the NotificationResource
            // pattern in HandleInertiaRequests).
            'scores' => $this->whenLoaded(
                'scores',
                fn () => PromotionReadinessScoreResource::collection($this->scores)->resolve($request),
            ),
        ];
    }
}
