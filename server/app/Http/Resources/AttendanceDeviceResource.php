<?php

namespace App\Http\Resources;

use App\Models\AttendanceDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceDevice
 */
class AttendanceDeviceResource extends JsonResource
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
            'type' => $this->type,
            'work_location_id' => $this->work_location_id,
            'location_name' => $this->whenLoaded('location', fn () => $this->location?->name),
            // Never the key: only enough of it to tell two apart.
            'key_hint' => $this->api_key_hint,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_seen_human' => $this->last_seen_at?->diffForHumans(),
            'is_active' => (bool) $this->is_active,
            'punches_count' => (int) ($this->punches_count ?? 0),
            'csv_mapping' => $this->csv_mapping,
        ];
    }
}
