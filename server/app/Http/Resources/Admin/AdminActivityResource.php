<?php

namespace App\Http\Resources\Admin;

use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin activity log (ADR 0085). The actor is the name and email
 * copied at the time, so a deleted admin's rows still say who it was.
 *
 * @mixin AdminActivityLog
 */
class AdminActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'event_label' => $this->eventLabel(),
            'description' => $this->description,
            'actor' => $this->actor_name !== null || $this->actor_email !== null ? [
                'id' => $this->admin_id,
                'name' => $this->actor_name,
                'email' => $this->actor_email,
            ] : null,
            'subject_type' => $this->subject_type !== null ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
