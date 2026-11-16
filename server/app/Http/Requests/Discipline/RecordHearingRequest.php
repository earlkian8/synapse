<?php

namespace App\Http\Requests\Discipline;

use Illuminate\Foundation\Http\FormRequest;

/**
 * What happened at a hearing, and whether the employee came (ADR 0105).
 */
class RecordHearingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attended' => ['required', 'boolean'],
            'hearing_notes' => ['required', 'string', 'min:10', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hearing_notes.required' => 'Write down what was said, or that they didn’t come.',
        ];
    }
}
