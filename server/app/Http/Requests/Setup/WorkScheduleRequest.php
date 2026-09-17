<?php

namespace App\Http\Requests\Setup;

use App\Models\WorkSchedule;
use App\Support\Attendance\SchedulePatternWriter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create or update a work schedule — the shift **template** (ADR 0037): a type,
 * a cycle, and one day row per day of that cycle.
 *
 * Times are "HH:MM". An end at or before the start crosses midnight, which is how
 * an overnight shift is written; a day may carry more than one segment, which is
 * how a split shift is.
 *
 * `start_time`, `end_time` and `work_days` are no longer submitted — they are
 * derived from the pattern by {@see SchedulePatternWriter}.
 */
class WorkScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(WorkSchedule::TYPES)],
            'cycle_length_days' => ['required', 'integer', 'min:1', 'max:'.WorkSchedule::MAX_CYCLE_LENGTH_DAYS],
            'cycle_anchor_date' => ['nullable', 'date_format:Y-m-d', 'required_unless:cycle_length_days,'.WorkSchedule::WEEKLY_CYCLE_LENGTH],
            'grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'weekly_required_minutes' => ['nullable', 'integer', 'min:0', 'max:10080'],

            'days' => ['required', 'array', 'min:1', 'max:'.WorkSchedule::MAX_CYCLE_LENGTH_DAYS],
            'days.*.is_rest_day' => ['boolean'],
            'days.*.segments' => ['nullable', 'array', 'max:4'],
            'days.*.segments.*.start' => ['required_with:days.*.segments', 'date_format:H:i'],
            'days.*.segments.*.end' => ['required_with:days.*.segments', 'date_format:H:i'],
            'days.*.required_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'days.*.core_start' => ['nullable', 'date_format:H:i'],
            'days.*.core_end' => ['nullable', 'date_format:H:i'],
            'days.*.earliest_start' => ['nullable', 'date_format:H:i'],
            'days.*.latest_end' => ['nullable', 'date_format:H:i'],
            'days.*.unpaid_break_minutes' => ['nullable', 'integer', 'min:0', 'max:480'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $days = (array) $this->input('days', []);

                if (count($days) !== $this->integer('cycle_length_days')) {
                    $validator->errors()->add('days', 'Give the pattern one row for each day of the cycle.');

                    return;
                }

                $working = array_filter($days, fn ($day): bool => ! (bool) ($day['is_rest_day'] ?? false));

                if ($working === []) {
                    $validator->errors()->add('days', 'A schedule needs at least one working day.');
                }

                if ($this->input('type') === 'flexible') {
                    $this->validateCoreWindows($validator, $working);
                }
            },
        ];
    }

    /**
     * A flexible day's core window is what lateness is judged against, so a
     * working day without one would silently never be late.
     *
     * @param  array<int, array<string, mixed>>  $working
     */
    private function validateCoreWindows(Validator $validator, array $working): void
    {
        foreach ($working as $index => $day) {
            if (($day['core_start'] ?? null) === null || ($day['core_end'] ?? null) === null) {
                $validator->errors()->add(
                    "days.{$index}.core_start",
                    'A flexible working day needs the core hours everyone must be present for.',
                );
            }
        }
    }

    protected function prepareForValidation(): void
    {
        $days = array_values((array) $this->input('days', []));

        foreach ($days as $index => $day) {
            $days[$index]['is_rest_day'] = (bool) ($day['is_rest_day'] ?? false);
        }

        $this->merge([
            'days' => $days,
            'cycle_length_days' => $this->integer('cycle_length_days') ?: WorkSchedule::WEEKLY_CYCLE_LENGTH,
        ]);
    }
}
