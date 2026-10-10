<?php

namespace App\Http\Requests\Performance;

use App\Models\PerformanceGoal;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set or edit a goal (ADR 0073). HR names the people and the cycle; someone
 * setting their own goal names only the cycle (`$forOthers` false). A library
 * entry may stand in for the wording and target; the workflow fills what was
 * left blank from it and keeps a percentage goal at 0 → 100.
 */
class GoalRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $forOthers = $creating && $this->routeIs('performance.goals.store');

        return [
            'employee_ids' => $forOthers ? ['required', 'array', 'min:1', 'max:200'] : ['prohibited'],
            'employee_ids.*' => ['integer', 'distinct', TenantRule::exists('employees')],
            'evaluation_period_id' => $creating
                ? ['required', 'integer', TenantRule::exists('evaluation_periods')]
                : ['prohibited'],
            'goal_template_id' => $creating
                ? ['nullable', 'integer', TenantRule::exists('goal_templates')]
                : ['prohibited'],
            'title' => [Rule::requiredIf(! $this->filled('goal_template_id')), 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'measure' => ['nullable', Rule::in(PerformanceGoal::MEASURES)],
            'start_value' => ['nullable', 'numeric', 'between:-1000000000,1000000000'],
            'target_value' => ['nullable', 'numeric', 'between:-1000000000,1000000000', Rule::requiredIf(fn (): bool => $this->input('measure') === 'number' && ! $this->filled('goal_template_id'))],
            'unit' => ['nullable', 'string', 'max:40'],
            'weight' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'due_on' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_ids.required' => 'Choose who the goal is for.',
            'title.required' => 'Give the goal a title, or start from the library.',
            'target_value.required' => 'Set the number to reach.',
        ];
    }
}
