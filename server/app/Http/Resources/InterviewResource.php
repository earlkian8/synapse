<?php

namespace App\Http\Resources;

use App\Models\Interview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interview
 */
class InterviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'scheduled_human' => $this->scheduled_at?->diffForHumans(),
            'scheduled_label' => $this->scheduled_at?->format('M j, Y · g:i A'),
            'mode' => $this->mode,
            'location' => $this->location,
            'notes' => $this->notes,
            'result' => $this->result,
            'feedback' => $this->feedback,
            'interviewer' => $this->whenLoaded('interviewer', fn () => $this->interviewer?->full_name),
            'interviewer_id' => $this->interviewer_id,
        ];
    }
}
