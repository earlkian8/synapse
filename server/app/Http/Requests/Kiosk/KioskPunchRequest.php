<?php

namespace App\Http\Requests\Kiosk;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A punch at a kiosk (ADR 0092): the ticket the person's PIN was exchanged for,
 * the punch, and the camera's photo when the kiosk has a camera.
 */
class KioskPunchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:48'],
            'type' => ['required', Rule::in(AttendancePunch::TYPES)],
            'photo' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
