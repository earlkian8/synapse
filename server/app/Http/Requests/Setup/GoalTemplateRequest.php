<?php

namespace App\Http\Requests\Setup;

use App\Models\PerformanceGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A goal-library entry (ADR 0073): wording, and how it is measured — progress to
 * 100 %, or a number from a start to a target in a unit.
 */
class GoalTemplateRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $number = $this->input('measure') === 'number';

        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'measure' => ['required', Rule::in(PerformanceGoal::MEASURES)],
            'start_value' => [$number ? 'required' : 'nullable', 'numeric', 'between:-1000000000,1000000000'],
            'target_value' => [$number ? 'required' : 'nullable', 'numeric', 'between:-1000000000,1000000000'],
            'unit' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A number goal has to go somewhere.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('measure') === 'number'
                    && is_numeric($this->input('start_value'))
                    && is_numeric($this->input('target_value'))
                    && (float) $this->input('start_value') === (float) $this->input('target_value')) {
                    $validator->errors()->add('target_value', 'The target has to differ from where the goal starts.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'start_value.required' => 'Say where the number starts.',
            'target_value.required' => 'Set the number to reach.',
        ];
    }
}
