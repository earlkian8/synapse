<?php

namespace App\Http\Requests\Discipline;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A written explanation to a notice, and whether a hearing is asked for
 * (ADR 0105).
 */
class ExplanationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'explanation' => ['required', 'string', 'min:10', 'max:10000'],
            'hearing' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'explanation.required' => 'Write your explanation.',
        ];
    }
}
