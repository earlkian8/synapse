<?php

namespace App\Http\Requests\Discipline;

use App\Models\DisciplinaryCase;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A preventive suspension while a case is heard (ADR 0105): from when, and
 * for how many days — at most 30.
 */
class PreventiveSuspensionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preventive_from' => ['required', 'date'],
            'days' => ['required', 'integer', 'min:1', 'max:'.DisciplinaryCase::MAX_PREVENTIVE_DAYS],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days.max' => 'A preventive suspension runs at most 30 days.',
        ];
    }
}
