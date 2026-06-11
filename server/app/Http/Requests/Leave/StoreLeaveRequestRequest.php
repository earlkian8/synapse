<?php

namespace App\Http\Requests\Leave;

use App\Models\LeaveType;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * File or edit a leave request. The chargeable `days` are never trusted from the
 * client — they are computed server-side from the date range. A half day is only
 * valid for a single-day request whose type permits it.
 */
class StoreLeaveRequestRequest extends FormRequest
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
            'leave_type_id' => [
                'required', 'integer',
                Rule::exists('leave_types', 'id')
                    ->where(fn ($query) => $query->where('organization_id', $orgId)->where('is_active', true)->whereNull('deleted_at')),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'is_half_day' => ['boolean'],
            'half_day_period' => ['nullable', 'required_if:is_half_day,true', Rule::in(['morning', 'afternoon'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Cross-field rules a half day cannot satisfy on its own: it must be a single
     * day, and the chosen type must allow it.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->boolean('is_half_day')) {
                return;
            }

            $start = $this->date('start_date');
            $end = $this->date('end_date');

            if ($start && $end && ! $start->isSameDay($end)) {
                $validator->errors()->add('is_half_day', 'A half day must start and end on the same date.');

                return;
            }

            $type = LeaveType::find($this->integer('leave_type_id'));

            if ($type && ! $type->allow_half_day) {
                $validator->errors()->add('is_half_day', "{$type->name} does not allow half-day leave.");
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_half_day' => $this->boolean('is_half_day')]);
    }

    /**
     * The validated payload, with `half_day_period` cleared when not a half day.
     *
     * @return array{start: Carbon, end: Carbon, isHalfDay: bool, period: ?string}
     */
    public function dateRange(): array
    {
        return [
            'start' => $this->date('start_date'),
            'end' => $this->date('end_date'),
            'isHalfDay' => $this->boolean('is_half_day'),
            'period' => $this->boolean('is_half_day') ? $this->string('half_day_period')->toString() : null,
        ];
    }
}
