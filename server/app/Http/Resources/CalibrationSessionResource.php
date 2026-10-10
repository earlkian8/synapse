<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Support\Performance\CalibrationWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A calibration session (ADR 0073): its cycle, what it covers, when, who takes
 * part, and how far it has got.
 *
 * @mixin CalibrationSession
 */
class CalibrationSessionResource extends JsonResource
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
            'status' => $this->status,
            'scheduled_for' => $this->scheduled_for?->toDateString(),
            'notes' => $this->notes,
            'department_ids' => $this->departmentScope(),
            'scope_label' => CalibrationWorkflow::scopeLabel($this->resource),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'adjustments_count' => (int) ($this->adjustments_count ?? 0),
            'facilitator' => $this->whenLoaded('facilitator', fn () => $this->facilitator?->full_name),
            'participants' => $this->whenLoaded('participants', fn () => $this->participants
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->full_name])
                ->values()
                ->all()),
            'period' => $this->whenLoaded('period', fn () => $this->period ? [
                'id' => $this->period->id,
                'name' => $this->period->name,
                'status' => $this->period->status,
            ] : null),
        ];
    }
}
