<?php

namespace App\Http\Requests\Onboarding;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'program_id' => ['nullable', 'integer', Rule::exists('onboarding_programs', 'id')],
        ];
    }
}
