<?php

namespace App\Http\Requests\Attendance;

use App\Support\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Re-apply current schedules to the recorded days in a period (ADR 0036): a
 * `from`–`to` range of work dates, optionally narrowed to one department. Capped,
 * because each day is re-judged one at a time — a month (the board's widest view)
 * fits comfortably.
 */
class ReapplyScheduleRequest extends FormRequest
{
    /** The most days one request may re-judge. */
    public const MAX_DAYS = 62;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department' => [
                'nullable', 'integer',
                Rule::exists('departments', 'id')->where('organization_id', app(Tenancy::class)->id()),
            ],
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

                $days = CarbonImmutable::parse($this->string('from')->toString())
                    ->diffInDays(CarbonImmutable::parse($this->string('to')->toString())) + 1;

                if ($days > self::MAX_DAYS) {
                    $validator->errors()->add('to', 'Re-apply schedules to at most '.self::MAX_DAYS.' days at a time.');
                }
            },
        ];
    }
}
