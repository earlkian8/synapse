<?php

namespace App\Http\Resources;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @mixin ActivityLog
 */
class ActivityLogResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'causer' => $this->resolveCauser(),
            'subject_type' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'subject_label' => $this->subject_label,
            'properties' => $this->properties,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'created_display' => $this->created_at?->format('M j, Y g:i A'),
        ];
    }

    /**
     * Resolve a compact representation of the actor.
     *
     * @return array<string, mixed>|null
     */
    private function resolveCauser(): ?array
    {
        $causer = $this->causer;

        if (! $causer) {
            return null;
        }

        return [
            'id' => $causer->id,
            'full_name' => $causer->full_name,
            'email' => $causer->email,
            'initials' => Str::upper(Str::substr((string) $causer->first_name, 0, 1).Str::substr((string) $causer->last_name, 0, 1)),
            'avatar' => $causer->profile_photo
                ? Storage::disk('public')->url($causer->profile_photo)
                : null,
        ];
    }
}
