<?php

namespace App\Http\Resources;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AttendanceRecord
 */
class AttendanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Transient roster rows (employees with no punches) have no key yet.
            'id' => $this->exists ? $this->id : null,
            'hashid' => $this->exists ? $this->hashid : null,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'scheduled_start' => $this->formatTime($this->scheduled_start),
            'scheduled_end' => $this->formatTime($this->scheduled_end),

            'first_in_at' => $this->first_in_at?->toIso8601String(),
            'last_out_at' => $this->last_out_at?->toIso8601String(),
            'worked_minutes' => (int) $this->worked_minutes,
            'break_minutes' => (int) $this->break_minutes,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'overtime_minutes' => (int) $this->overtime_minutes,

            'is_manual' => (bool) $this->is_manual,
            'remarks' => $this->remarks,
            'approval_status' => $this->approval_status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->full_name),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? ['id' => $this->employee->department->id, 'name' => $this->employee->department->name]
                    : null,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? ['id' => $this->employee->position->id, 'title' => $this->employee->position->title]
                    : null,
            ] : null),

            'punches' => $this->whenLoaded('punches', fn () => $this->punches
                ->map(fn (AttendancePunch $punch): array => [
                    'id' => $punch->id,
                    'type' => $punch->type,
                    'punched_at' => $punch->punched_at?->toIso8601String(),
                    'source' => $punch->source,
                    'latitude' => $punch->latitude !== null ? (float) $punch->latitude : null,
                    'longitude' => $punch->longitude !== null ? (float) $punch->longitude : null,
                    'accuracy' => $punch->accuracy !== null ? (float) $punch->accuracy : null,
                    'photo' => $punch->photo_url,
                    'note' => $punch->note,
                    'recorder' => $punch->relationLoaded('recorder') ? $punch->recorder?->full_name : null,
                ])
                ->values()
                ->all()),
        ];
    }

    /**
     * Normalise a stored "HH:MM[:SS]" time to "HH:MM" for the client.
     */
    private function formatTime(?string $time): ?string
    {
        $time = trim((string) $time);

        return $time === '' ? null : substr($time, 0, 5);
    }
}
