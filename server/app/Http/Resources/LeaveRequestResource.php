<?php

namespace App\Http\Resources;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'status' => $this->status,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'days' => (float) $this->days,
            'is_half_day' => $this->is_half_day,
            'half_day_period' => $this->half_day_period,
            'reason' => $this->reason,
            'review_note' => $this->review_note,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),

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
            'type' => $this->whenLoaded('type', fn () => $this->type ? [
                'id' => $this->type->id,
                'name' => $this->type->name,
                'code' => $this->type->code,
                'color' => $this->type->color,
                'is_paid' => $this->type->is_paid,
            ] : null),
            'filer' => $this->whenLoaded('filer', fn () => $this->filer?->full_name),
            'reviewer' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->full_name),

            // Balance snapshot for this request's type/year, attached by the controller on show.
            'balance' => $this->when(isset($this->balanceSnapshot), fn () => $this->balanceSnapshot),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
