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

            // Enrollment aggregates (populated via withCount on the query).
            'enrollments_count' => (int) ($this->enrollments_count ?? 0),
            'active_count' => $active,
            'completed_count' => (int) ($this->completed_enrollments_count ?? 0),
            'seats_remaining' => $capacity === null ? null : max(0, $capacity - $active),
            'is_full' => $capacity !== null && $active >= $capacity,

            'enrollments' => TrainingEnrollmentResource::collection($this->whenLoaded('enrollments')),
        ];
    }
}
