<?php

namespace App\Http\Requests\Setup;

use App\Models\Holiday;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a holiday (Company Setup → Work Schedule & Holidays). It
 * repeats by one rule (ADR 0078): not at all, every year on its date, a number
 * of days from Easter, or on the n-th weekday of a month. A movable holiday
 * needs no date: it is worked out from the rule. `location_ids` makes it local
 * to those sites; none keeps it everywhere.
 *
 * The rules are static, so the assistant validates its arguments with them too
 * — and a caller that only says `is_recurring` keeps working.
 */
class HolidayRequest extends FormRequest
{
    /** The furthest from Easter a holiday may be set — Ash Wednesday is −46, Pentecost +49. */
    public const MAX_EASTER_OFFSET = 70;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', Rule::in(Holiday::TYPES)],
            'is_recurring' => ['boolean'],
            'recurrence' => ['sometimes', 'nullable', Rule::in(Holiday::RECURRENCES)],
            'date' => ['required_unless:recurrence,easter,weekday', 'nullable', 'date'],
            'rule' => ['nullable', 'array'],
            'rule.offset' => ['required_if:recurrence,easter', 'nullable', 'integer', 'between:-'.self::MAX_EASTER_OFFSET.','.self::MAX_EASTER_OFFSET],
            'rule.month' => ['required_if:recurrence,weekday', 'nullable', 'integer', 'between:1,12'],
            'rule.weekday' => ['required_if:recurrence,weekday', 'nullable', 'integer', 'between:1,7'],
            'rule.week' => ['required_if:recurrence,weekday', 'nullable', 'integer', Rule::in([1, 2, 3, 4, Holiday::LAST_WEEK])],
            'location_ids' => ['sometimes', 'array', 'max:100'],
            'location_ids.*' => ['integer', 'distinct', TenantRule::exists('work_locations', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.required_unless' => 'Pick the date.',
            'rule.offset.required_if' => 'Say how many days from Easter it falls.',
            'rule.month.required_if' => 'Pick the month.',
            'rule.weekday.required_if' => 'Pick the weekday.',
            'rule.week.required_if' => 'Pick which week of the month.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_recurring' => $this->boolean('is_recurring')]);

        if ($this->filled('recurrence')) {
            $this->merge(['is_recurring' => $this->input('recurrence') !== 'none']);
        }
    }

    /**
     * What the holiday is saved with: only the rule's own numbers, and no date
     * for a movable one (the model works it out).
     *
     * @return array<string, mixed>
     */
    public function holiday(): array
    {
        $data = $this->safe()->except(['location_ids', 'rule']);
        $recurrence = $data['recurrence'] ?? null;
        $rule = $this->validated('rule') ?? [];

        $data['rule'] = match ($recurrence) {
            'easter' => ['offset' => (int) $rule['offset']],
            'weekday' => ['month' => (int) $rule['month'], 'weekday' => (int) $rule['weekday'], 'week' => (int) $rule['week']],
            default => null,
        };

        if (in_array($recurrence, Holiday::MOVABLE, true)) {
            $data['date'] = null;
        }

        return $data;
    }

    /**
     * The sites the holiday is local to, or null when the form did not say.
     *
     * @return list<int>|null
     */
    public function locationIds(): ?array
    {
        return $this->has('location_ids')
            ? array_values(array_map('intval', $this->validated('location_ids') ?? []))
            : null;
    }
}
