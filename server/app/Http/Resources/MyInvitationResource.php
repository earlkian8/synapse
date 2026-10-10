<?php

namespace App\Http\Resources;

use App\Models\EventAttendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of the signed-in employee's invitations, as My events (web) and the mobile
 * app show it: their own answer, and the event — when, where, who runs it, how
 * many are coming, and the repeat it belongs to.
 *
 * @mixin EventAttendee
 */
class MyInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $event = $this->event;

        return [
            'id' => $this->id,
            'response' => $this->response,
            'responded_at' => $this->responded_at?->toIso8601String(),
            'event' => [
                'hashid' => $event->hashid,
                'title' => $event->title,
                'description' => $event->description,
                'type' => $event->type,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'location' => $event->location,
                'status' => $event->status(),
                'reminder_minutes' => $event->reminder_minutes,
                'room' => $event->room ? [
                    'name' => $event->room->name,
                    'location' => $event->room->location,
                ] : null,
                'series' => $event->series ? ['id' => $event->series->id, 'summary' => $event->series->summary()] : null,
                'organizer' => $event->organizer
                    ? trim("{$event->organizer->first_name} {$event->organizer->last_name}")
                    : null,
                'attendees_count' => (int) ($event->attendees_count ?? 0),
                'attending_count' => (int) ($event->attending_count ?? 0),
            ],
        ];
    }
}
