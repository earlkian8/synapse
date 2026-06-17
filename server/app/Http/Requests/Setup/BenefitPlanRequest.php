<?php

namespace App\Http\Requests\Setup;

use App\Models\BenefitPlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a benefit plan (Company Setup → Benefits). Costs are the
 * per-period employee / employer shares; the active flag is normalised so an
 * omitted switch reads as false.
 */
class BenefitPlanRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(BenefitPlan::CATEGORIES)],
            'provider' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'employee_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'employer_cost' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'frequency' => ['required', Rule::in(BenefitPlan::FREQUENCIES)],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
