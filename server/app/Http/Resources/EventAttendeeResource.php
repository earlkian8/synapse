<?php

namespace App\Http\Resources;

use App\Models\EventAttendee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EventAttendee
 */
class EventAttendeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'response' => $this->response,
            'notified_at' => $this->notified_at?->toIso8601String(),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? $this->employee->position->title
                    : null,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? $this->employee->department->name
                    : null,
            ] : null),
        ];
    }
}
