<?php

namespace App\Http\Resources;

use App\Models\TrainingSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A program's session (ADR 0074): its day and local times, where, and — when
 * the attendances are loaded — how many were marked each way.
 *
 * @mixin TrainingSession
 */
class TrainingSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'session_date' => $this->session_date->toDateString(),
            'starts_at' => $this->startTime(),
            'ends_at' => $this->endTime(),
            'location' => $this->location,
            'notes' => $this->notes,
            'is_held' => $this->isHeld(),

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'hashid' => $this->program->hashid,
                'name' => $this->program->name,
                'provider' => $this->program->provider,
            ] : null),

            // Each mark, for the attendance sheet. A list of pairs, not a map keyed
            // by enrollment id: a resource re-indexes an all-numeric-keyed array.
            'marks' => $this->whenLoaded('attendances', fn () => $this->attendances
                ->map(fn ($a): array => ['enrollment_id' => $a->training_enrollment_id, 'status' => $a->status])
                ->values()
                ->all()),
            'tally' => $this->whenLoaded('attendances', fn () => [
                'present' => $this->attendances->where('status', 'present')->count(),
                'late' => $this->attendances->where('status', 'late')->count(),
                'absent' => $this->attendances->where('status', 'absent')->count(),
                'excused' => $this->attendances->where('status', 'excused')->count(),
            ]),
        ];
    }
}
