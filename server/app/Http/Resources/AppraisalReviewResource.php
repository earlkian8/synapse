<?php

namespace App\Http\Resources;

use App\Models\AppraisalReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A review request as its reviewer reads it (ADR 0072): whose appraisal, in which
 * cycle, what they are to the person, when it is due and where it stands.
 *
 * @mixin AppraisalReview
 */
class AppraisalReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $evaluation = $this->relationLoaded('evaluation') ? $this->evaluation : null;
        $subject = $evaluation?->relationLoaded('employee') ? $evaluation->employee : null;

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'relationship' => $this->relationship,
            'status' => $this->status,
            'due_on' => $this->due_on?->toDateString(),
            'overdue' => $this->isOverdue(),
            'strengths' => $this->strengths,
            'improvements' => $this->improvements,
            'decline_reason' => $this->decline_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'declined_at' => $this->declined_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->full_name),
            // Whether the appraisal is still open to reviews.
            'open' => $evaluation === null ? null : $evaluation->isEditable(),

            'subject' => $subject ? [
                'id' => $subject->id,
                'full_name' => $subject->full_name,
                'initials' => $subject->initials(),
                'photo' => $subject->photo_url,
                'position' => $subject->relationLoaded('position') ? $subject->position?->title : null,
                'department' => $subject->relationLoaded('department') ? $subject->department?->name : null,
            ] : null,

            'period' => $evaluation?->relationLoaded('period') && $evaluation->period ? [
                'id' => $evaluation->period->id,
                'name' => $evaluation->period->name,
                'start_date' => $evaluation->period->start_date?->toDateString(),
                'end_date' => $evaluation->period->end_date?->toDateString(),
            ] : null,

            'template_name' => $evaluation?->template_name,
        ];
    }
}
