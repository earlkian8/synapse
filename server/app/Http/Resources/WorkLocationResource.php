<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\WorkLocation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WorkLocation
 */
class WorkLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'address' => $this->address,
            'latitude' => (float) $this->latitude,
            'longitude' => (float) $this->longitude,
            'radius_meters' => (int) $this->radius_meters,
            'default_work_schedule_id' => $this->default_work_schedule_id,
            'attendance_policy_id' => $this->attendance_policy_id,
            'schedule_name' => $this->whenLoaded('defaultSchedule', fn () => $this->defaultSchedule?->name),
            'policy_name' => $this->whenLoaded('policy', fn () => $this->policy?->name),
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->trashed(),
            'employees_count' => (int) ($this->employees_count ?? 0),
            'devices_count' => (int) ($this->devices_count ?? 0),
            // Who is based here, and for whom it is the primary site.
            'people' => $this->whenLoaded('employees', fn () => $this->employees
                ->map(fn (Employee $employee): array => [
                    'id' => $employee->id,
                    'is_primary' => (bool) $employee->pivot->is_primary,
                ])
                ->values()
                ->all()),
        ];
    }
}
