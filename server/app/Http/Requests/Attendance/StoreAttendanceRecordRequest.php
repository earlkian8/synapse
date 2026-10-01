<?php

namespace App\Http\Requests\Attendance;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR manual entry of a Daily Time Record for an employee on a given date. The
 * four punch times are optional clock-face times ("HH:MM") combined with the
 * work_date; the record's totals/status are recomputed server-side from them.
 */
class StoreAttendanceRecordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $orgId = app(Tenancy::class)->id();

        return [
            'employee_id' => [
                'required', 'integer',
                Rule::exists('employees', 'id')->where('organization_id', $orgId)->whereNull('deleted_at'),
            ],
            'work_date' => ['required', 'date'],
            'time_in' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
