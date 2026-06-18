<?php

namespace App\Http\Resources;

use App\Models\PerformanceEvaluation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PerformanceEvaluation
 */
class PerformanceEvaluationResource extends JsonResource
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
            'overall_score' => $this->overall_score === null ? null : (float) $this->overall_score,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'acknowledged_at' => $this->acknowledged_at?->toIso8601String(),
            'remarks' => $this->remarks,
            'scores_count' => (int) ($this->scores_count ?? 0),

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

            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'hashid' => $this->period->hashid,
                'name' => $this->period->name,
                'status' => $this->period->status,
                'start_date' => $this->period->start_date?->toDateString(),
                'end_date' => $this->period->end_date?->toDateString(),
            ] : null),

            'evaluator' => $this->whenLoaded('evaluator', fn () => $this->evaluator ? [
                'id' => $this->evaluator->id,
                'name' => trim("{$this->evaluator->first_name} {$this->evaluator->last_name}"),
            ] : null),

            'scores' => PerformanceScoreResource::collection($this->whenLoaded('scores')),
        ];
    }
}
