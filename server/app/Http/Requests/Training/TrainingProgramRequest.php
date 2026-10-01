<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update a training program. The date window is optional (an open-ended
 * program is allowed) but the end must not precede the start; capacity is optional
 * (null = uncapped).
 */
class TrainingProgramRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'provider' => ['nullable', 'string', 'max:160'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
