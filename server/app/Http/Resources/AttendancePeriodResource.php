<?php

namespace App\Http\Resources;

use App\Models\AttendancePeriod;
use App\Support\OrganizationClock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance period (ADR 0039), with its lock checklist when the controller
 * attached one.
 *
 * @mixin AttendancePeriod
 */
class AttendancePeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $today = OrganizationClock::today();

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'label' => $this->label(),
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'status' => $this->status,
            // Where the period sits against the organisation's today.
            'phase' => match (true) {
                $this->end_date->toDateString() < $today => 'past',
                $this->start_date->toDateString() > $today => 'upcoming',
                default => 'current',
            },
            'locked_at' => $this->locked_at?->toIso8601String(),
            'locked_by' => $this->whenLoaded('locker', fn () => $this->locker?->full_name),
            'lock_note' => $this->lock_note,
            'unlocked_at' => $this->unlocked_at?->toIso8601String(),
            'unlocked_by' => $this->whenLoaded('unlocker', fn () => $this->unlocker?->full_name),
            'unlock_reason' => $this->unlock_reason,
            'has_export' => $this->export_path !== null,
            'checklist' => $this->when(isset($this->checklist), fn () => $this->checklist),
        ];
    }
}
