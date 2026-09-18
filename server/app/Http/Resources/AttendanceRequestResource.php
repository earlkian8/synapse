<?php

namespace App\Http\Resources;

use App\Models\AttendancePeriod;
use App\Models\AttendancePunch;
use App\Models\AttendanceRequest;
use App\Support\Attendance\PeriodLock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attendance request (ADR 0039), for the inbox, the review modal, the
 * employee's own list and the mobile app.
 *
 * `day` is the day the request concerns as it stands now — the punches a
 * correction would change, the overtime an overtime request asks to approve — so
 * a reviewer compares the ask with the record rather than taking it on trust.
 * `replaced` is what an approved correction took away.
 *
 * @mixin AttendanceRequest
 */
class AttendanceRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $lock = app(PeriodLock::class);
        $from = $this->start_date->toDateString();
        $to = $this->end_date->toDateString();
        $locked = $lock->ranges()->first(fn (AttendancePeriod $period): bool => $period->start_date->toDateString() <= $to
            && $period->end_date->toDateString() >= $from);
        $isOwn = $this->relationLoaded('employee') && $this->employee?->user_id !== null && $this->employee->user_id === $user?->id;

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'type' => $this->type,
            'status' => $this->status,
            'start_date' => $from,
            'end_date' => $to,
            'payload' => $this->payload ?? [],
            'reason' => $this->reason,
            'attachment' => $this->attachmentUrl(),
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),

            // Inside a locked period nothing about it can be decided (ADR 0039).
            'locked_period' => $locked?->label(),

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
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->full_name),
            'requester' => $this->whenLoaded('requester', fn () => $this->requester?->full_name),

            'day' => $this->whenLoaded('record', fn () => $this->record ? [
                'hashid' => $this->record->hashid,
                'status' => $this->record->status,
                'first_in_at' => $this->record->first_in_at?->toIso8601String(),
                'last_out_at' => $this->record->last_out_at?->toIso8601String(),
                'worked_minutes' => (int) $this->record->worked_minutes,
                'overtime_minutes' => (int) $this->record->overtime_minutes,
                'approved_overtime_minutes' => (int) $this->record->approved_overtime_minutes,
                'punches' => $this->record->relationLoaded('punches') ? $this->punches($this->record->punches) : [],
            ] : null),
            'replaced' => $this->whenLoaded('replacedPunches', fn () => $this->punches($this->replacedPunches)),

            // What the person looking at it may do.
            'can' => [
                'review' => $user !== null && $this->status === 'pending' && ! $isOwn && $locked === null
                    && $user->can('attendance.requests.review'),
                'cancel' => $user !== null && $this->status === 'pending'
                    && ($isOwn || $this->requested_by === $user->id || $user->can('attendance.manage')),
            ],
        ];
    }

    /**
     * @param  iterable<AttendancePunch>  $punches
     * @return list<array{type: string, punched_at: ?string, source: string}>
     */
    private function punches(iterable $punches): array
    {
        $out = [];

        foreach ($punches as $punch) {
            $out[] = [
                'type' => $punch->type,
                'punched_at' => $punch->punched_at?->toIso8601String(),
                'source' => $punch->source,
            ];
        }

        return $out;
    }
}
