<?php

namespace App\Http\Resources;

use App\Models\GoalTemplate;
use App\Support\Performance\GoalProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An entry in the goal library (ADR 0073).
 *
 * @mixin GoalTemplate
 */
class GoalTemplateResource extends JsonResource
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
            'measure' => $this->measure,
            'start_value' => (float) $this->start_value,
            'target_value' => (float) $this->target_value,
            'unit' => $this->unit,
            'target_label' => $this->measure === 'percent'
                ? 'Progress to 100%'
                : GoalProgress::format((float) $this->start_value, $this->measure, $this->unit)
                    .' → '.GoalProgress::format((float) $this->target_value, $this->measure, $this->unit),
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->deleted_at !== null,
            'goals_count' => (int) ($this->goals_count ?? 0),
        ];
    }
}
