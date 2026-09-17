<?php

namespace App\Http\Requests\Attendance;

use App\Models\Employee;
use App\Models\WorkSchedule;
use App\Support\Attendance\ScheduleAssigner;
use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Put one or more employees on a schedule from a date (ADR 0037).
 *
 * Overlaps are not validated away here — {@see ScheduleAssigner}
 * makes room by closing or splitting whatever the new range lands on, which is
 * what "from Monday, Ana is on nights" means.
 */
class AssignScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organization = app(Tenancy::class)->id();

        return [
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('organization_id', $organization)],
            'work_schedule_id' => ['required', 'integer', Rule::exists('work_schedules', 'id')->where('organization_id', $organization)],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'cycle_offset' => ['nullable', 'integer', 'min:0', 'max:'.(WorkSchedule::MAX_CYCLE_LENGTH_DAYS - 1)],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('employee_ids')) {
            return;
        }

        // The roster assigns a selection; the employee profile assigns the one
        // person its URL names. Both arrive at the rules as a list.
        $employee = $this->route('employee');

        $this->merge([
            'employee_ids' => array_values(array_filter([
                $employee instanceof Employee ? $employee->id : $this->input('employee_id'),
            ])),
        ]);
    }
}
