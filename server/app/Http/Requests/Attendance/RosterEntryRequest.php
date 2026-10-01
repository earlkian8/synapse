<?php

namespace App\Http\Requests\Attendance;

use App\Support\Tenancy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Set one employee's shift for one date (ADR 0037) — a swap, a call-in, or a day
 * off. The override either borrows a template's pattern for that date or states
 * its own segments; a rest day states neither.
 */
class RosterEntryRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $organization = app(Tenancy::class)->id();

        return [
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('organization_id', $organization)],
            'date' => ['required', 'date_format:Y-m-d'],
            'is_rest_day' => ['boolean'],
            'work_schedule_id' => [
                'nullable', 'integer',
                Rule::exists('work_schedules', 'id')->where('organization_id', $organization),
            ],
            'segments' => ['nullable', 'array', 'max:4'],
            'segments.*.start' => ['required_with:segments', 'date_format:H:i'],
            'segments.*.end' => ['required_with:segments', 'date_format:H:i'],
            'required_minutes' => ['nullable', 'integer', 'min:0', 'max:1440'],
            'reason' => ['nullable', 'string', 'max:160'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || $this->boolean('is_rest_day')) {
                    return;
                }

                if ($this->input('segments') === null && $this->input('work_schedule_id') === null) {
                    $validator->errors()->add('segments', 'Choose a schedule to borrow, or give the day its own hours.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_rest_day' => $this->boolean('is_rest_day')]);
    }
}
