<?php

namespace App\Http\Requests\Performance;

use App\Models\PerformanceGoal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A check-in on a goal (ADR 0073): where it stands now, how it is going, and a
 * note.
 */
class GoalCheckInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'value' => ['required', 'numeric', 'between:-1000000000,1000000000'],
            'health' => ['required', Rule::in(PerformanceGoal::HEALTHS)],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.required' => 'Say where the goal stands now.',
            'health.required' => 'Say how it is going.',
        ];
    }
}
