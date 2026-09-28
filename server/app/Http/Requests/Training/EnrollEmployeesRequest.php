<?php

namespace App\Http\Requests\Training;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Enroll one or more employees into a training program in a single action. Only
 * the employee ids are accepted — new enrollments always start as `enrolled`;
 * status, score and remarks are set later per person. Capacity and
 * already-enrolled are reconciled in the controller so a partial enroll still
 * succeeds for everyone who fits.
 */
class EnrollEmployeesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ids' => ['required', 'array', 'min:1'],
            'employee_ids.*' => ['integer', TenantRule::exists('employees')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_ids.required' => 'Select at least one employee to enroll.',
            'employee_ids.min' => 'Select at least one employee to enroll.',
        ];
    }
}
