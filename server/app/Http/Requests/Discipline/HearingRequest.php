<?php

namespace App\Http\Requests\Discipline;

use App\Support\OrganizationClock;
use Illuminate\Foundation\Http\FormRequest;

/**
 * When and where a hearing or conference is held (ADR 0105).
 */
class HearingRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'hearing_at' => ['required', 'date', 'after_or_equal:'.OrganizationClock::today()],
            'hearing_place' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'hearing_at.after_or_equal' => 'Set the hearing for today or later.',
        ];
    }
}
