<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The punch a QR scan's ticket is spent on (ADR 0103), with the selfie a
 * policy may ask for.
 */
class QrPunchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:48'],
            'type' => ['required', 'string', Rule::in(AttendancePunch::TYPES)],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
