<?php

namespace App\Http\Resources;

use App\Models\BenefitPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BenefitPlan
 */
class BenefitPlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $employeeCost = (float) $this->employee_cost;
        $employerCost = (float) $this->employer_cost;

        return [
            'id' => $this->id,
            'hashid' => $this->hashid,
            'name' => $this->name,
            'category' => $this->category,
            'provider' => $this->provider,
            'description' => $this->description,
            'employee_cost' => $employeeCost,
            'employer_cost' => $employerCost,
            'total_cost' => round($employeeCost + $employerCost, 2),
            'frequency' => $this->frequency,
            'is_active' => (bool) $this->is_active,
            'is_archived' => $this->deleted_at !== null,

            // Per-employee monthly-equivalent cost, for cost rollups.
            'monthly_employee_cost' => $this->toMonthly($employeeCost),
            'monthly_employer_cost' => $this->toMonthly($employerCost),

            // Enrollment counts (populated via withCount on the query).
            'enrollments_count' => (int) ($this->enrollments_count ?? 0),
            'active_count' => (int) ($this->active_enrollments_count ?? 0),

            'enrollments' => BenefitEnrollmentResource::collection($this->whenLoaded('enrollments')),
        ];
    }
}
