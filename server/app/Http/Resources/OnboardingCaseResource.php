<?php

namespace App\Http\Resources;

use App\Models\OnboardingCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnboardingCase
 */
class OnboardingCaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $progress = $this->progressSummary();

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'status' => $this->status,
            'is_active' => $this->isActive(),
            'start_date' => $this->start_date?->toDateString(),
            'target_end_date' => $this->target_end_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'notes' => $this->notes,

            'progress' => $progress,

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'employee_no' => $this->employee->employee_no,
                'photo' => $this->employee->photo_url,
                'employment_type' => $this->employee->employment_type,
                'department' => $this->employee->relationLoaded('department') && $this->employee->department
                    ? ['id' => $this->employee->department->id, 'name' => $this->employee->department->name]
                    : null,
                'position' => $this->employee->relationLoaded('position') && $this->employee->position
                    ? ['id' => $this->employee->position->id, 'title' => $this->employee->position->title]
                    : null,
                'date_hired' => $this->employee->date_hired?->toDateString(),
            ] : null),
            'program' => $this->whenLoaded('program', fn () => $this->program ? [
                'id' => $this->program->id,
                'name' => $this->program->name,
            ] : null),

            'tasks' => $this->whenLoaded(
                'tasks',
                fn () => OnboardingTaskResource::collection($this->tasks)->resolve($request),
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'created_human' => $this->created_at?->diffForHumans(),
            'updated_human' => $this->updated_at?->diffForHumans(),
        ];
    }
}
