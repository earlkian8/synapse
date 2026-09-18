<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendancePeriod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The calendar attendance closes on, and how many days before a period ends HR
 * is reminded to lock it (ADR 0039). Authorization is the route's
 * `can:attendance.period.manage`.
 */
class AttendancePeriodSettingsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'frequency' => ['required', Rule::in(AttendancePeriod::FREQUENCIES)],
            'reminder_days' => ['required', 'integer', 'min:0', 'max:14'],
        ];
    }
}
