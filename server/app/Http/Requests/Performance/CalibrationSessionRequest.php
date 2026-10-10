<?php

namespace App\Http\Requests\Performance;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Open or edit a calibration session (ADR 0073). The cycle and the departments it
 * covers are set when it opens; afterwards its name, date, notes and who takes
 * part can change.
 */
class CalibrationSessionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'name' => ['required', 'string', 'max:120'],
            'evaluation_period_id' => $creating
                ? ['required', 'integer', TenantRule::exists('evaluation_periods')]
                : ['prohibited'],
            'department_ids' => $creating ? ['nullable', 'array', 'max:50'] : ['prohibited'],
            'department_ids.*' => ['integer', 'distinct', TenantRule::exists('departments')],
            'scheduled_for' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'participant_ids' => ['nullable', 'array', 'max:30'],
            'participant_ids.*' => ['integer', 'distinct', TenantRule::member()],
        ];
    }
}
