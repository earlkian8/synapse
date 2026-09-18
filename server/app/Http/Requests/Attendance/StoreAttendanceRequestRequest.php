<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendanceRequest;
use App\Services\Assistant\Modules\AttendanceModule;
use App\Support\OrganizationClock;
use App\Support\TenantRule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Filing an attendance request (ADR 0039) — from the web, the mobile API, and
 * (through {@see rulesFor()} and {@see checks()}) the assistant, so all three
 * refuse the same things in the same words.
 *
 * Every type needs a reason. What else it needs depends on the type:
 *
 *  - `correction` — at least one of the four punch times, for a day that has
 *    already started (the organisation's today at the latest).
 *  - `overtime` — the minutes asked for. A day after today is a pre-approval.
 *  - `official_business` / `remote_work` — a range of at most
 *    {@see AttendanceRequest::MAX_RANGE_DAYS} days, and optionally the hours and
 *    where.
 *
 * `employee_id` files on somebody else's behalf, which needs `attendance.manage`
 * — the route's `can:attendance.request` covers filing for yourself.
 */
class StoreAttendanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employeeId = $this->integer('employee_id');

        if ($employeeId === 0 || $employeeId === $this->user()->employee?->id) {
            return true;
        }

        return $this->user()->can('attendance.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::rulesFor((string) $this->input('type'));
    }

    /**
     * @return array<int, \Closure(Validator): void>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => self::checks($validator)];
    }

    /**
     * The rules for one type. Shared with {@see AttendanceModule}.
     *
     * @return array<string, mixed>
     */
    public static function rulesFor(string $type): array
    {
        $time = ['nullable', 'date_format:H:i'];

        $rules = [
            'type' => ['required', 'string', Rule::in(AttendanceRequest::TYPES)],
            'employee_id' => ['nullable', 'integer', TenantRule::exists('employees')->whereNull('deleted_at')],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
        ];

        return $rules + match ($type) {
            'correction' => [
                'time_in' => $time,
                'break_start' => $time,
                'break_end' => $time,
                'time_out' => $time,
            ],
            'overtime' => [
                'minutes' => ['required', 'integer', 'min:1', 'max:960'],
            ],
            'official_business', 'remote_work' => [
                'start_time' => $time,
                'end_time' => $time,
                'location' => ['nullable', 'string', 'max:255'],
            ],
            default => [],
        };
    }

    /**
     * What the rules alone cannot say: a correction proposes something and is
     * for a day that has begun; a range is not a quarter.
     */
    public static function checks(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $data = $validator->getData();
        $type = (string) ($data['type'] ?? '');
        $start = (string) ($data['start_date'] ?? '');
        $end = (string) ($data['end_date'] ?? '') ?: $start;

        if ($type === 'correction') {
            $proposed = array_filter(
                array_map(fn (string $field): string => trim((string) ($data[$field] ?? '')), AttendanceRequest::CORRECTION_FIELDS),
            );

            if ($proposed === []) {
                $validator->errors()->add('time_in', 'Give at least one time the day should show.');
            }

            if ($start > OrganizationClock::today()) {
                $validator->errors()->add('start_date', 'A correction is for a day that has already started.');
            }
        }

        if (in_array($type, ['official_business', 'remote_work'], true)
            && CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) >= AttendanceRequest::MAX_RANGE_DAYS) {
            $validator->errors()->add('end_date', 'One request covers at most '.AttendanceRequest::MAX_RANGE_DAYS.' days.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::messagesFor();
    }

    /**
     * The wording, shared with the assistant's validator.
     *
     * @return array<string, string>
     */
    public static function messagesFor(): array
    {
        return [
            'reason.required' => 'Say why — the reviewer reads it.',
            'minutes.required' => 'Say how much overtime.',
            'end_date.after_or_equal' => 'The last day cannot be before the first.',
        ];
    }
}
