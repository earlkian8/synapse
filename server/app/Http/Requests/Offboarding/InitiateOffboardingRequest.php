<?php

namespace App\Http\Requests\Offboarding;

use App\Models\OffboardingCase;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Start offboarding for an employee, capturing the exit kind, the key dates and
 * the reason. Authorization is handled by the route's `can:offboarding.manage`.
 */
class InitiateOffboardingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')],
            'type' => ['required', Rule::in(OffboardingCase::TYPES)],
            // Optional explicit clearance template; omitted = best match / standard.
            'offboarding_program_id' => ['nullable', 'integer', TenantRule::exists('offboarding_programs')],
            'notice_date' => ['nullable', 'date'],
            'last_working_day' => ['nullable', 'date', 'after_or_equal:notice_date'],
            'reason' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
