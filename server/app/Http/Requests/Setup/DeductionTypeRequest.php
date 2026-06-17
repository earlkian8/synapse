<?php

namespace App\Http\Requests\Setup;

use App\Models\DeductionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a deduction type (Company Setup → Payroll). The UI edits a
 * flat percentage rate (with an optional monthly cap); the controller assembles
 * the {@see DeductionType} `computation` config from it.
 */
class DeductionTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', 'string', Rule::in(DeductionType::KINDS)],
            'is_mandatory' => ['boolean'],
            'rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'cap' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_mandatory' => $this->boolean('is_mandatory')]);
    }
}
