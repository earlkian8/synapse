<?php

namespace App\Http\Requests\Discipline;

use App\Support\OrganizationClock;
use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An incident report (ADR 0105): who, what offense if it fits one, when and
 * where, what happened in enough detail to charge on, and who saw it.
 */
class ReportIncidentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', TenantRule::exists('employees')->whereNull('deleted_at')],
            'conduct_offense_id' => ['nullable', 'integer', TenantRule::exists('conduct_offenses')->whereNull('deleted_at')],
            'incident_date' => ['required', 'date', 'before_or_equal:'.OrganizationClock::today()],
            'incident_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:160'],
            'narrative' => ['required', 'string', 'min:20', 'max:5000'],
            'witnesses' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_id.required' => 'Choose who the incident is about.',
            'incident_date.before_or_equal' => 'An incident is reported once it has happened.',
            'narrative.required' => 'Say what happened.',
            'narrative.min' => 'Say what happened in more detail: a notice to explain has to narrate the facts.',
        ];
    }
}
