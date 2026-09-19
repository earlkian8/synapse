<?php

namespace App\Http\Resources;

use App\Models\AttendancePunch;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRequest;
use App\Support\Attendance\PeriodLock;
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
        $lockedPeriod = $this->work_date !== null
            ? app(PeriodLock::class)->periodFor($this->work_date->toDateString())
            : null;

        return [
            // Transient roster rows (employees with no punches) have no key yet.
            'id' => $this->exists ? $this->id : null,
            'hashid' => $this->exists ? $this->hashid : null,
            'work_date' => $this->work_date?->toDateString(),
            'status' => $this->status,
            'scheduled_start' => $this->formatTime($this->scheduled_start),
            'scheduled_end' => $this->formatTime($this->scheduled_end),
            'scheduled_start_at' => $this->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $this->scheduled_end_at?->toIso8601String(),

            // What the day was judged by (ADR 0036) — the schedule and holiday in
            // force when it opened, not the ones in force today.
            'schedule_name' => $this->rules['schedule_name'] ?? null,
            'holiday' => isset($this->rules['holiday_type'])
                ? ['name' => $this->rules['holiday_name'] ?? null, 'type' => $this->rules['holiday_type']]
                : null,
            // …and the attendance policy (ADR 0038). A day recorded before
            // policies existed has none named: it was judged by the built-in rules.
            'policy' => [
                'name' => $this->rules['policy']['name'] ?? null,
                'source' => $this->rules['policy']['source'] ?? 'fallback',
            ],
            'flags' => array_values($this->flags ?? []),

            'first_in_at' => $this->first_in_at?->toIso8601String(),
            'last_out_at' => $this->last_out_at?->toIso8601String(),
            'worked_minutes' => (int) $this->worked_minutes,
            'break_minutes' => (int) $this->break_minutes,
            'late_minutes' => (int) $this->late_minutes,
            'undertime_minutes' => (int) $this->undertime_minutes,
            'overtime_minutes' => (int) $this->overtime_minutes,

            // The buckets (ADR 0038): regular + overtime = worked; night, rest day
            // and holiday are tags over those same minutes.
            'regular_minutes' => (int) $this->regular_minutes,
            'approved_overtime_minutes' => (int) $this->approved_overtime_minutes,
            'night_minutes' => (int) $this->night_minutes,
            'rest_day_minutes' => (int) $this->rest_day_minutes,
            'holiday_minutes' => (int) $this->holiday_minutes,

            'is_manual' => (bool) $this->is_manual,
            'remarks' => $this->remarks,
            'approval_status' => $this->approval_status,
            'approved_at' => $this->approved_at?->toIso8601String(),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->full_name),

            // A day in a locked period cannot change through any path (ADR 0039);
            // the UI hides what it would refuse anyway.
            'is_locked' => $lockedPeriod !== null,
            'locked_period' => $lockedPeriod?->label(),

            // The requests that concern the day, and the punches an edit or a
            // correction replaced — attached by the day-detail fetch.
            'requests' => $this->whenLoaded('dayRequests', fn () => $this->getRelation('dayRequests')
                ->map(fn (AttendanceRequest $request): array => [
                    'hashid' => $request->hashid,
                    'type' => $request->type,
                    'status' => $request->status,
                    'start_date' => $request->start_date->toDateString(),
                    'end_date' => $request->end_date->toDateString(),
                    'payload' => $request->payload ?? [],
                    'reason' => $request->reason,
                    'review_note' => $request->review_note,
                ])
                ->values()
                ->all()),
            'replaced_punches' => $this->whenLoaded('replacedPunches', fn () => $this->replacedPunches
                ->map(fn (AttendancePunch $punch): array => [
                    'id' => $punch->id,
                    'type' => $punch->type,
                    'punched_at' => $punch->punched_at?->toIso8601String(),
                    'source' => $punch->source,
                    'replaced_at' => $punch->deleted_at?->toIso8601String(),
                    'by_request' => $punch->replaced_by_request_id !== null,
                ])
                ->values()
                ->all()),

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

                    // What capture learned (ADR 0040): the nearest site and
                    // whether the punch was on it, the device that sent it, and
                    // whether a phone queued it while offline.
                    'location' => $punch->relationLoaded('location') && $punch->location !== null
                        ? ['name' => $punch->location->name, 'radius_meters' => (int) $punch->location->radius_meters]
                        : null,
                    'distance_meters' => $punch->distance_meters,
                    'within_geofence' => $punch->within_geofence,
                    'device' => $punch->relationLoaded('device') && $punch->device !== null
                        ? ['name' => $punch->device->name, 'type' => $punch->device->type]
                        : null,
                    'offline' => $punch->device_punched_at !== null,
                    'received_at' => $punch->received_at?->toIso8601String(),
                    'clock_skew_seconds' => $punch->clock_skew_seconds,
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
