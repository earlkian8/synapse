<?php

namespace App\Http\Requests\Onboarding;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Start an onboarding case for an employee, optionally from a specific program.
 */
class StartOnboardingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', TenantRule::exists('onboarding_programs')],
        ];
    }
}
