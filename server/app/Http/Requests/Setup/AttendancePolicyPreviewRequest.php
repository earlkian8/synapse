<?php

namespace App\Http\Requests\Setup;

use App\Support\Attendance\WorkedExample;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * The worked example's question: judge this sample day by these settings
 * ({@see WorkedExample}). The settings are held to exactly the rules a saved
 * policy is ({@see AttendancePolicyRequest::settingsRules()}), so the example
 * never shows a verdict the policy could not be saved with.
 */
class AttendancePolicyPreviewRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...AttendancePolicyRequest::settingsRules(),

            'sample' => ['required', 'array'],
            'sample.day' => ['required', 'in:working,rest_day,holiday'],
            'sample.shift_start' => ['required', 'date_format:H:i'],
            'sample.shift_end' => ['required', 'date_format:H:i'],
            'sample.required_minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'sample.grace_minutes' => ['required', 'integer', 'min:0', 'max:240'],
            'sample.time_in' => ['required', 'date_format:H:i'],
            'sample.time_out' => ['nullable', 'date_format:H:i'],
            'sample.break_start' => ['nullable', 'date_format:H:i', 'required_with:sample.break_end'],
            'sample.break_end' => ['nullable', 'date_format:H:i', 'required_with:sample.break_start'],
            'sample.week_regular_minutes_before' => ['nullable', 'integer', 'min:0', 'max:10080'],
            'sample.month_excused_late_minutes_before' => ['nullable', 'integer', 'min:0', 'max:1440'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty()) {
                    AttendancePolicyRequest::validateSettings($validator, (array) $this->input('settings', []));
                }
            },
        ];
    }

    /**
     * Answer in JSON rather than redirecting back: the editor asks this while the
     * owner types, and shows what is wrong beside the example instead of reloading
     * the page (only `api/*` renders JSON errors app-wide).
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors()->toArray(),
        ], 422));
    }
}
