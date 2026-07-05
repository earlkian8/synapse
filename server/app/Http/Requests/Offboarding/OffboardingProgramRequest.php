<?php

namespace App\Http\Requests\Offboarding;

use App\Models\OffboardingCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an offboarding program (clearance template) together with its
 * blueprint sign-off items. The full item list is sent each save and synced
 * wholesale. Authorization is handled by `can:offboarding.manage-programs`.
 */
class OffboardingProgramRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'exit_type' => ['nullable', Rule::in(OffboardingCase::TYPES)],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],

            'items' => ['array'],
            'items.*.item' => ['required', 'string', 'max:255'],
            'items.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'items.*.use_employee_department' => ['boolean'],
        ];
    }
}
