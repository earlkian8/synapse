<?php

namespace App\Http\Resources;

use App\Models\PerformanceForecastRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PerformanceForecastRun
 */
class PerformanceForecastRunResource extends JsonResource
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
            'exceeds_count' => (int) $this->exceeds_count,
            'on_track_count' => (int) $this->on_track_count,
            'below_count' => (int) $this->below_count,
            'average_rating' => $this->average_rating === null ? null : (float) $this->average_rating,
            'average_confidence' => $this->average_confidence === null ? null : (float) $this->average_confidence,
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator
                ? trim("{$this->generator->first_name} {$this->generator->last_name}")
                : null),
            'target_period' => $this->whenLoaded('targetPeriod', fn () => $this->targetPeriod ? [
                'name' => $this->targetPeriod->name,
                'start_date' => $this->targetPeriod->start_date?->toDateString(),
                'end_date' => $this->targetPeriod->end_date?->toDateString(),
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),

            // Resolve to a plain array so Inertia sends a JSON list, not a
            // `{ data: [...] }`-wrapped object (mirrors the NotificationResource
            // pattern in HandleInertiaRequests).
            'forecasts' => $this->whenLoaded(
                'forecasts',
                fn () => PerformanceForecastScoreResource::collection($this->forecasts)->resolve($request),
            ),
        ];
    }
}
