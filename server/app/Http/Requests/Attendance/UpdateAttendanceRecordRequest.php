<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HR correction of an existing Daily Time Record: edit the punch times and/or the
 * remarks. Times are clock-face "HH:MM" values combined with the record's date;
 * totals and status are recomputed from them.
 */
class UpdateAttendanceRecordRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'time_in' => ['nullable', 'date_format:H:i'],
            'break_start' => ['nullable', 'date_format:H:i'],
            'break_end' => ['nullable', 'date_format:H:i'],
            'time_out' => ['nullable', 'date_format:H:i'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
