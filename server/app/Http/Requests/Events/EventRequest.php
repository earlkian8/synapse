<?php

namespace App\Http\Requests\Events;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an event / meeting. The start is required; the end is optional
 * (a quick meeting may have only a start) but must not precede it.
 */
class EventRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::in(Event::TYPES)],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:160'],
        ];
    }
}
