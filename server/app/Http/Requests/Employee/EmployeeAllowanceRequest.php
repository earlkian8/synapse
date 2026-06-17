<?php

namespace App\Http\Requests\Employee;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Assign or update one of an employee's recurring allowances — an allowance type
 * (from the tenant's Company-Setup catalogue) and a per-employee peso amount.
 * Used for both store and update.
 */
class EmployeeAllowanceRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(Tenancy::class)->id();

        return [
            'allowance_type_id' => [
                'required', 'integer',
                Rule::exists('allowance_types', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
