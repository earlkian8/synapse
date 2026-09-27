<?php

namespace App\Http\Resources;

use App\Models\PromotionReadinessRun;
use App\Support\Ml\UnassessedEmployees;
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
            // Whose model scored it: the organisation's own (ADR 0046) or the general one.
            'scored_by' => $this->local_model_id !== null ? 'own' : 'general',
            // "Average" for this run's tiers and odds: the promotion rate of the
            // history its model learned from — the organisation's own, when it scored
            // the run; null for the general model, whose rate the page already knows.
            'base_rate' => $this->baseRate(),
            'employees_scored' => (int) $this->employees_scored,
            'high_count' => (int) $this->high_count,
            'medium_count' => (int) $this->medium_count,
            'low_count' => (int) $this->low_count,
            'average_score' => $this->average_score === null ? null : (float) $this->average_score,
            // Employees the model declined — no completed appraisal — and why.
            'unassessed' => UnassessedEmployees::resolve($this->unassessed),
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

    private function baseRate(): ?float
    {
        $counts = $this->local_model_id !== null ? ($this->localModel?->counts ?? []) : [];
        $examples = ($counts['promoted'] ?? 0) + ($counts['not_promoted'] ?? 0);

        return $examples > 0 ? round($counts['promoted'] / $examples, 4) : null;
    }
}
