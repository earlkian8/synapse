<?php

namespace App\Http\Requests\Onboarding;

use App\Models\OnboardingTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an onboarding program (template) together with its blueprint
 * tasks. The full task list is sent each save and synced wholesale.
 */
class StoreOnboardingProgramRequest extends FormRequest
{
    public const EMPLOYMENT_TYPES = ['regular', 'probationary', 'contractual', 'part_time'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'employment_type' => ['nullable', Rule::in(self::EMPLOYMENT_TYPES)],
            'is_default' => ['boolean'],
            'is_active' => ['boolean'],

            'tasks' => ['array'],
            'tasks.*.title' => ['required', 'string', 'max:255'],
            'tasks.*.description' => ['nullable', 'string', 'max:2000'],
            'tasks.*.category' => ['required', Rule::in(OnboardingTask::CATEGORIES)],
            'tasks.*.due_offset_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
