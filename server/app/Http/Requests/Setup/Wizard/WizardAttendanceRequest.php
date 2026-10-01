<?php

namespace App\Http\Requests\Setup\Wizard;

use App\Http\Requests\Setup\AttendancePolicyRequest;
use App\Support\Attendance\AttendancePolicyPresets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The wizard's Attendance step (ADR 0038): how the company's days are judged,
 * and — optionally — the hours most people work.
 *
 * A preset is always the starting point. Adopted as it is, its settings are
 * resolved server-side from {@see AttendancePolicyPresets} rather than posted,
 * the same way a hiring blueprint's stages are. **Customised**, the settings the
 * company adjusted are held to exactly the rules the Attendance Policies screen
 * applies ({@see AttendancePolicyRequest::settingsRules()}), so the wizard cannot
 * save a policy the editor would refuse.
 *
 * Writing the default schedule is a Work Schedule change, so it additionally
 * needs that screen's permission.
 */
class WizardAttendanceRequest extends FormRequest
{
    public const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    public function authorize(): bool
    {
        return ! $this->boolean('schedule.create') || $this->user()->can('setup.schedule.manage');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $customised = $this->boolean('customised');
        $schedule = $this->boolean('schedule.create');

        return [
            'preset' => ['required', 'string', Rule::in(AttendancePolicyPresets::keys())],
            'name' => ['nullable', 'string', 'max:120'],
            'customised' => ['boolean'],
            ...($customised ? AttendancePolicyRequest::settingsRules() : []),

            'schedule' => ['array'],
            'schedule.create' => ['boolean'],
            'schedule.name' => $schedule ? ['required', 'string', 'max:120'] : ['nullable'],
            'schedule.start' => $schedule ? ['required', 'date_format:H:i'] : ['nullable'],
            'schedule.end' => $schedule ? ['required', 'date_format:H:i', 'different:schedule.start'] : ['nullable'],
            'schedule.days' => $schedule ? ['required', 'array', 'min:1', 'max:7'] : ['nullable', 'array'],
            'schedule.days.*' => ['string', Rule::in(self::WEEKDAYS)],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->boolean('customised')) {
                    AttendancePolicyRequest::validateSettings($validator, (array) $this->input('settings', []));
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'preset.required' => 'Pick how your days are judged, or skip this step.',
            'preset.in' => 'Pick how your days are judged, or skip this step.',
            'schedule.name.required' => 'Give the schedule a name.',
            'schedule.days.required' => 'Pick at least one working day.',
            'schedule.days.min' => 'Pick at least one working day.',
            'schedule.end.different' => 'The shift has to end at a different time from when it starts.',
        ];
    }
}
