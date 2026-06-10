<?php

namespace App\Http\Resources;

use App\Models\JobApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin JobApplication
 */
class JobApplicationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'rating' => $this->rating,
            'expected_salary' => $this->expected_salary,
            'cover_note' => $this->cover_note,
            'rejected_reason' => $this->rejected_reason,
            'is_hired' => $this->isHired(),

            'applicant' => $this->whenLoaded('applicant', fn () => $this->applicant ? [
                'id' => $this->applicant->id,
                'full_name' => $this->applicant->full_name,
                'initials' => $this->applicant->initials(),
                'email' => $this->applicant->email,
                'phone' => $this->applicant->phone,
                'headline' => $this->applicant->headline,
                'source' => $this->applicant->source,
                'resume_url' => $this->applicant->resumeUrl(),
                'notes' => $this->applicant->notes,
            ] : null),
            'job_posting' => $this->whenLoaded('jobPosting', fn () => $this->jobPosting ? [
                'id' => $this->jobPosting->id,
                'title' => $this->jobPosting->title,
            ] : null),
            'hired_employee' => $this->whenLoaded('hiredEmployee', fn () => $this->hiredEmployee ? [
                'id' => $this->hiredEmployee->id,
                'full_name' => $this->hiredEmployee->full_name,
                'employee_no' => $this->hiredEmployee->employee_no,
            ] : null),

            'interviews' => InterviewResource::collection($this->whenLoaded('interviews')),
            'interviews_count' => $this->whenCounted('interviews'),

            'applicant_id' => $this->applicant_id,
            'hired_employee_id' => $this->hired_employee_id,

            'applied_at' => $this->applied_at?->toIso8601String(),
            'applied_human' => $this->applied_at?->diffForHumans(),
            'age_days' => $this->applied_at?->diffInDays(now()),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'updated_human' => $this->updated_at?->diffForHumans(),
        ];
    }
}
