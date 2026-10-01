<?php

namespace App\Http\Resources;

use App\Models\EmployeeAward;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeAward
 */
class EmployeeAwardResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'awarded_on' => $this->awarded_on?->toDateString(),
            'reason' => $this->reason,

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

            'award_type' => $this->whenLoaded('awardType', fn () => $this->awardType ? [
                'id' => $this->awardType->id,
                'hashid' => $this->awardType->hashid,
                'name' => $this->awardType->name,
                'color' => $this->awardType->color,
            ] : null),

            'granted_by' => $this->whenLoaded('grantedBy', fn () => $this->grantedBy ? [
                'id' => $this->grantedBy->id,
                'name' => trim("{$this->grantedBy->first_name} {$this->grantedBy->last_name}"),
            ] : null),
        ];
    }
}
