<?php

namespace App\Http\Requests\Payroll;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Hand-edit a payslip's lines: the full set of allowance earning lines and
 * deduction lines is replaced from the request, then totals are recomputed
 * server-side (basic & overtime pay stay auto). Each line carries a label, a
 * non-negative amount, and an optional type from the tenant's catalogue.
 */
class UpdatePayslipRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(Tenancy::class)->id();

        return [
            'earnings' => ['present', 'array'],
            'earnings.*.label' => ['required', 'string', 'max:120'],
            'earnings.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'earnings.*.allowance_type_id' => [
                'nullable', 'integer',
                Rule::exists('allowance_types', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],

            'deductions' => ['present', 'array'],
            'deductions.*.label' => ['required', 'string', 'max:120'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'deductions.*.deduction_type_id' => [
                'nullable', 'integer',
                Rule::exists('deduction_types', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
        ];
    }
}
