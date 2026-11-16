<?php

namespace App\Http\Requests\Discipline;

use App\Models\DisciplinaryCase;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The decision on a case (ADR 0105): upheld or not, the penalty and its
 * dates, and the reasons — every circumstance weighed.
 */
class DecisionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'finding' => ['required', Rule::in(['upheld', 'cleared'])],
            'penalty' => ['nullable', 'required_if:finding,upheld', Rule::in(array_keys(DisciplinaryCase::PENALTIES))],
            'suspension_days' => ['nullable', 'required_if:penalty,suspension', 'integer', 'min:1', 'max:'.DisciplinaryCase::MAX_SUSPENSION_DAYS],
            'penalty_from' => ['nullable', 'date'],
            'decision' => ['required', 'string', 'min:30', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'penalty.required_if' => 'Choose the penalty for an upheld charge.',
            'decision.min' => 'Give the reasons: that every circumstance was weighed, and what decided it.',
        ];
    }
}
