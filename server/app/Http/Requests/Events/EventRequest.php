<?php

namespace App\Http\Requests\Events;

use App\Models\Event;
use App\Models\EventSeries;
use App\Support\Events\Recurrence;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update an event / meeting. The start is required; the end is optional
 * (a quick meeting may have only a start) but must not precede it.
 *
 * ADR 0070 adds a room (from this workspace), an automatic reminder, a repeat
 * rule when scheduling, and — for an occurrence of a series — whether an edit
 * covers it alone or it and every later one. Whether the room is free, and
 * whether the repeat stays within its limits, is EventWorkflow's call.
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
            'room_id' => ['nullable', 'integer', TenantRule::exists('rooms')],
            'reminder_minutes' => ['nullable', 'integer', Rule::in(Event::REMINDER_CHOICES)],
            'scope' => ['nullable', Rule::in(['this', 'following'])],

            'repeat' => ['nullable', 'array'],
            'repeat.frequency' => ['required_with:repeat', Rule::in(EventSeries::FREQUENCIES)],
            'repeat.interval' => ['nullable', 'integer', 'min:1', 'max:'.Recurrence::MAX_INTERVAL],
            'repeat.weekdays' => ['nullable', 'array'],
            'repeat.weekdays.*' => ['integer', 'between:1,7'],
            'repeat.until' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'repeat.count' => ['nullable', 'integer', 'min:2', 'max:'.Recurrence::MAX_OCCURRENCES],
        ];
    }

    /**
     * The event's own fields and its repeat rule, without the edit scope.
     *
     * @return array<string, mixed>
     */
    public function eventData(): array
    {
        $data = $this->safe()->except(['scope']);

        // An empty rule from the form ("Does not repeat") is no rule.
        if (empty($data['repeat']['frequency'] ?? null)) {
            unset($data['repeat']);
        }

        return $data;
    }

    public function scope(): string
    {
        return $this->validated('scope') ?? 'this';
    }
}
