<?php

namespace App\Http\Resources;

use App\Models\OnboardingCase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

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
        $progress = $this->progress();

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
                'photo' => $this->employee->photo ? Storage::disk('public')->url($this->employee->photo) : null,
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

    /**
     * Progress summary — derived from loaded tasks when present, else from the
     * `*_count` aggregates added by the index query.
     *
     * @return array{total: int, done: int, resolved: int, overdue: int, percent: int}
     */
    private function progress(): array
    {
        if ($this->relationLoaded('tasks')) {
            $tasks = $this->tasks;
            $total = $tasks->count();
            $done = $tasks->where('status', 'done')->count();
            $resolved = $tasks->whereIn('status', ['done', 'skipped'])->count();
            $overdue = $tasks->filter->isOverdue()->count();
        } else {
            $total = (int) ($this->tasks_count ?? 0);
            $done = (int) ($this->done_tasks_count ?? 0);
            $resolved = (int) ($this->resolved_tasks_count ?? 0);
            $overdue = (int) ($this->overdue_tasks_count ?? 0);
        }

        return [
            'total' => $total,
            'done' => $done,
            'resolved' => $resolved,
            'overdue' => $overdue,
            'percent' => $total > 0 ? (int) round(($resolved / $total) * 100) : 0,
        ];
    }
}
