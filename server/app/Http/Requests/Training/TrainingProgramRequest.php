<?php

namespace App\Http\Requests\Training;

use App\Models\TrainingProgram;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create or update a training program. The date window is optional (an open-ended
 * program is allowed) but the end must not precede the start; capacity is optional
 * (null = uncapped). Who may join it themselves (ADR 0074), whether it issues a
 * certificate and for how long, and its budget (ADR 0075) are optional on an
 * update, so a change that leaves them out keeps them.
 */
class TrainingProgramRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'provider' => ['nullable', 'string', 'max:160'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'enrollment_mode' => ['sometimes', Rule::in(TrainingProgram::ENROLLMENT_MODES)],
            'issues_certificate' => ['sometimes', 'boolean'],
            // Months a certificate stays valid; blank = it never expires.
            'certificate_validity_months' => ['nullable', 'integer', 'min:1', 'max:240'],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'certificate_validity_months.max' => 'A certificate can be valid for at most 240 months (20 years).',
        ];
    }
}
