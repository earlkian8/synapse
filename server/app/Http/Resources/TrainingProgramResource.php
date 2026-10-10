<?php

namespace App\Http\Resources;

use App\Models\TrainingProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrainingProgram
 */
class TrainingProgramResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $capacity = $this->capacity === null ? null : (int) $this->capacity;
        $active = (int) ($this->active_enrollments_count ?? 0);

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'description' => $this->description,
            'provider' => $this->provider,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'capacity' => $capacity,
            'status' => $this->status(),
            'is_archived' => $this->deleted_at !== null,
            'enrollment_mode' => $this->enrollment_mode ?? 'closed',
            'issues_certificate' => (bool) $this->issues_certificate,
            'certificate_validity_months' => $this->certificate_validity_months === null ? null : (int) $this->certificate_validity_months,
            'budget' => $this->budget === null ? null : (float) $this->budget,
            'ai_insights' => $this->ai_insights ?? null,

            // Enrollment aggregates (populated via withCount on the query).
            'enrollments_count' => (int) ($this->enrollments_count ?? 0),
            'active_count' => $active,
            'completed_count' => (int) ($this->completed_enrollments_count ?? 0),
            'seats_remaining' => $capacity === null ? null : max(0, $capacity - $active),
            'is_full' => $capacity !== null && $active >= $capacity,
            'pending_count' => (int) ($this->pending_enrollments_count ?? 0),
            'sessions_count' => (int) ($this->sessions_count ?? 0),

            // Resolved to a plain array (not a bare resource collection) so Inertia
            // does not re-wrap it in a `data` key when serializing this nested prop.
            'enrollments' => $this->whenLoaded(
                'enrollments',
                fn () => TrainingEnrollmentResource::collection($this->enrollments)->resolve($request),
            ),
        ];
    }
}
