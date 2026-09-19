<?php

namespace App\Http\Requests\Devices;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A punch at the web kiosk (ADR 0040): who (their employee number or enrolment
 * id), which punch, and the photo the kiosk's camera took.
 */
class KioskPunchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_ref' => ['required', 'string', 'max:64'],
            'type' => ['required', Rule::in(AttendancePunch::TYPES)],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
