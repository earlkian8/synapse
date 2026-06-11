<?php

namespace App\Http\Requests\Onboarding;

use App\Models\OnboardingTask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or edit a single checklist task on a case.
 */
class StoreOnboardingTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', Rule::in(OnboardingTask::CATEGORIES)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'due_date' => ['nullable', 'date'],
        ];
    }
}
