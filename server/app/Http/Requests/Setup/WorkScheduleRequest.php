<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a work schedule (Company Setup → Work Schedule & Holidays).
 * Times are "HH:MM"; an end before the start is allowed (an overnight shift).
 */
class WorkScheduleRequest extends FormRequest
{
    /**
     * Short day names a schedule's working days are stored as.
     *
     * @var list<string>
     */
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => [Rule::in(self::DAYS)],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'required_hours' => ['required', 'numeric', 'min:0', 'max:24'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'work_days' => array_values(array_unique((array) $this->input('work_days', []))),
        ]);
    }
}
