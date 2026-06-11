<?php

namespace App\Http\Resources;

use App\Models\OnboardingTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OnboardingTask
 */
class OnboardingTaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'status' => $this->status,
            'is_resolved' => $this->isResolved(),
            'is_overdue' => $this->isOverdue(),

            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'full_name' => $this->assignee->full_name,
            ] : null),
            'completer' => $this->whenLoaded('completer', fn () => $this->completer?->full_name),

            'assigned_to' => $this->assigned_to,
            'due_date' => $this->due_date?->toDateString(),
            'due_human' => $this->due_date?->diffForHumans(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'completed_human' => $this->completed_at?->diffForHumans(),
            'sort_order' => $this->sort_order,
        ];
    }
}
