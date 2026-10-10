<?php

namespace App\Http\Resources;

use App\Models\GoalCheckIn;
use App\Models\PerformanceGoal;
use App\Support\Performance\GoalProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A goal as the Goals screens and the scorecard read it (ADR 0073): its target in
 * its own terms, how far along it is, how it is going, and — when loaded — its
 * check-ins.
 *
 * @mixin PerformanceGoal
 */
class PerformanceGoalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $format = fn (float $value): string => GoalProgress::format($value, $this->measure, $this->unit);

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'title' => $this->title,
            'description' => $this->description,
            'measure' => $this->measure,
            'start_value' => (float) $this->start_value,
            'target_value' => (float) $this->target_value,
            'current_value' => (float) $this->current_value,
            'unit' => $this->unit,
            'start_label' => $format((float) $this->start_value),
            'target_label' => $format((float) $this->target_value),
            'current_label' => $format((float) $this->current_value),
            'progress' => $this->progress(),
            'weight' => (float) $this->weight,
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status,
            'health' => $this->health,
            'is_stale' => $this->isStale(),
            'last_check_in_at' => $this->last_check_in_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'from_library' => $this->goal_template_id !== null,
            'created_by_owner' => $this->created_by !== null
                && $this->relationLoaded('employee')
                && $this->employee?->user_id === $this->created_by,
            'check_ins_count' => (int) ($this->check_ins_count ?? 0),

            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
                'initials' => $this->employee->initials(),
                'photo' => $this->employee->photo_url,
                'department' => $this->employee->relationLoaded('department') ? $this->employee->department?->name : null,
                'department_id' => $this->employee->department_id,
            ] : null),

            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'name' => $this->period->name,
                'status' => $this->period->status,
            ] : null),

            'check_ins' => $this->whenLoaded('checkIns', fn () => $this->checkIns->map(fn (GoalCheckIn $checkIn): array => [
                'id' => $checkIn->id,
                'value' => (float) $checkIn->value,
                'value_label' => $format((float) $checkIn->value),
                'progress' => GoalProgress::percent((float) $this->start_value, (float) $this->target_value, (float) $checkIn->value),
                'health' => $checkIn->health,
                'note' => $checkIn->note,
                'author' => $checkIn->author?->full_name,
                'by_owner' => $checkIn->author_id !== null && $checkIn->author_id === $this->employee?->user_id,
                'created_at' => $checkIn->created_at?->toIso8601String(),
            ])->values()->all()),
        ];
    }
}
