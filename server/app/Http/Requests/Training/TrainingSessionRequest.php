<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schedule or change a program's session (ADR 0074): a day and local start /
 * end times, an optional title, place and notes. A new session can repeat on
 * every day (or every weekday) up to `repeat_until`. Whether the days fall
 * within the program's dates is the workflow's to say, in words.
 */
class TrainingSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['nullable', 'string', 'max:120'],
            'session_date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:session_date'],
            'weekdays_only' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'repeat_until.after_or_equal' => 'Repeat until the day of the first session or later.',
        ];
    }
}
