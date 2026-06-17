<?php

namespace App\Http\Requests\Benefits;

use App\Models\BenefitEnrollment;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Enroll an employee in a benefit plan, or update an existing enrollment. The plan
 * comes from the route; the employee is only set when enrolling (it never changes
 * on update). Used for both store and update.
 */
class BenefitEnrollmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(Tenancy::class)->id();
        $isUpdate = $this->route('enrollment') !== null;

        return [
            'employee_id' => [
                $isUpdate ? 'sometimes' : 'required', 'integer',
                Rule::exists('employees', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'status' => ['required', Rule::in(BenefitEnrollment::STATUSES)],
            'reference_no' => ['nullable', 'string', 'max:120'],
            'enrolled_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:enrolled_on'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'status' => $this->string('status')->toString() ?: 'active',
        ]);
    }
}
