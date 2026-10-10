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
            'reminder_minutes' => $this->reminder_minutes,
            'reminder_sent_at' => $this->reminder_sent_at?->toIso8601String(),

            'room' => $this->whenLoaded('room', fn () => $this->room ? [
                'id' => $this->room->id,
                'hashid' => $this->room->hashid,
                'name' => $this->room->name,
                'location' => $this->room->location,
                'capacity' => $this->room->capacity,
            ] : null),

            // The repeat rule, and — on the event page — where this date sits in it.
            'series' => $this->whenLoaded('series', fn () => $this->series ? [
                'id' => $this->series->id,
                'summary' => $this->series->summary(),
                'position' => $this->series_position ?? null,
                'total' => $this->series_total ?? null,
            ] : null),

            'organizer' => $this->whenLoaded('organizer', fn () => $this->organizer ? [
                'id' => $this->organizer->id,
                'name' => trim("{$this->organizer->first_name} {$this->organizer->last_name}"),
            ] : null),

            // Attendee aggregates (populated via withCount on the query).
            'attendees_count' => (int) ($this->attendees_count ?? 0),
            'attending_count' => (int) ($this->attending_count ?? 0),

            // Resolved to a plain array (not a bare resource collection) so Inertia
            // does not re-wrap it in a `data` key when serializing this nested prop.
            'attendees' => $this->whenLoaded(
                'attendees',
                fn () => EventAttendeeResource::collection($this->attendees)->resolve($request),
            ),
        ];
    }
}
