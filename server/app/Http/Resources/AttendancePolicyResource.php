<?php

namespace App\Http\Resources;

use App\Models\AttendancePolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendancePolicy
 */
class AttendancePolicyResource extends JsonResource
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
            'description' => $this->description,
            'preset_key' => $this->preset_key,
            'preset_name' => $this->preset()['name'] ?? null,
            'is_default' => (bool) $this->is_default,

            // Complete and canonical, whatever version it was saved by.
            'settings' => $this->settings()->toArray(),

            // Where it is in force, so archiving one says what it would affect.
            'schedules_count' => (int) ($this->schedules_count ?? 0),
            'departments_count' => (int) ($this->departments_count ?? 0),
            'assignments_count' => (int) ($this->assignments_count ?? 0),

            'is_archived' => $this->trashed(),
        ];
    }
}
