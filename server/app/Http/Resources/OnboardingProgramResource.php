<?php

namespace App\Http\Resources;

use App\Models\OnboardingProgram;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnboardingProgram
 */
class OnboardingProgramResource extends JsonResource
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
            'employment_type' => $this->employment_type,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,

            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id' => $this->department->id,
                'name' => $this->department->name,
            ] : null),
            'department_id' => $this->department_id,

            'tasks' => $this->whenLoaded('tasks', fn () => $this->tasks->map(fn ($task) => [
                'id' => $task->id,
                'title' => $task->title,
                'description' => $task->description,
                'category' => $task->category,
                'due_offset_days' => $task->due_offset_days,
                'sort_order' => $task->sort_order,
            ])->values()),
            'tasks_count' => $this->whenCounted('tasks'),
            'cases_count' => $this->whenCounted('cases'),

            'created_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
