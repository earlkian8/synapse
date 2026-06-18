<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a KPI criterion (Company Setup → KPI & Evaluation Criteria).
 * The weight is the criterion's relative share of the overall score; the active
 * flag is normalised so an omitted switch reads as false.
 */
class KpiCriterionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'weight' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
