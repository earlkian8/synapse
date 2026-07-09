<?php

namespace App\Http\Resources;

use App\Models\TrainingEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TrainingEnrollment
 */
class TrainingEnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'score' => $this->score === null ? null : (float) $this->score,
            'completed_at' => $this->completed_at?->toDateString(),
            'enrolled_on' => $this->created_at?->toDateString(),
            'remarks' => $this->remarks,

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

            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'hashid' => $this->program->hashid,
                'name' => $this->program->name,
                'provider' => $this->program->provider,
                'status' => $this->program->status(),
            ] : null),
        ];
    }
}
