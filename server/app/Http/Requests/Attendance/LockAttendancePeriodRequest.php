<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Locking (or unlocking) an attendance period (ADR 0039). A reason is optional
 * to lock — the locker requires one when the checklist is still open — and
 * always required to unlock. Authorization is the route's permission.
 */
class LockAttendancePeriodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => [$this->routeIs('attendance.periods.unlock') ? 'required' : 'nullable', 'string', 'min:3', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say why the period is being reopened — it is kept in the log.',
        ];
    }
}
