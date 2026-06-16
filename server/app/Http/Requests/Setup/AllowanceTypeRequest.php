<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update an allowance type (Company Setup → Payroll). The taxable flag
 * is normalised so an omitted checkbox reads as false.
 */
class AllowanceTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'is_taxable' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_taxable' => $this->boolean('is_taxable')]);
    }
}
