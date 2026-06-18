<?php

namespace App\Http\Resources;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Event
 */
class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'title' => $this->title,
            'description' => $this->description,
            'type' => $this->type,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'location' => $this->location,
            'status' => $this->status(),
            'is_archived' => $this->deleted_at !== null,

            'organizer' => $this->whenLoaded('organizer', fn () => $this->organizer ? [
                'id' => $this->organizer->id,
                'name' => trim("{$this->organizer->first_name} {$this->organizer->last_name}"),
            ] : null),

            // Attendee aggregates (populated via withCount on the query).
            'attendees_count' => (int) ($this->attendees_count ?? 0),
            'attending_count' => (int) ($this->attending_count ?? 0),

            'attendees' => EventAttendeeResource::collection($this->whenLoaded('attendees')),
        ];
    }
}
