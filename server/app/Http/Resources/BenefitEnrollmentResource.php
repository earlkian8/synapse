<?php

namespace App\Http\Resources;

use App\Models\BenefitEnrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BenefitEnrollment
 */
class BenefitEnrollmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'reference_no' => $this->reference_no,
            'enrolled_on' => $this->enrolled_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'notes' => $this->notes,

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

            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id,
                'hashid' => $this->plan->hashid,
                'name' => $this->plan->name,
                'category' => $this->plan->category,
                'provider' => $this->plan->provider,
                'employee_cost' => (float) $this->plan->employee_cost,
                'employer_cost' => (float) $this->plan->employer_cost,
                'frequency' => $this->plan->frequency,
            ] : null),
        ];
    }
}
