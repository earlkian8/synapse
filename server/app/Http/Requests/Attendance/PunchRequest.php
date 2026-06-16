<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A clock punch (in / out / break) from web self-service or the mobile API. The
 * capture context — GPS coordinates, accuracy and an optional selfie — is all
 * optional; only the punch `type` is required.
 */
class PunchRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(AttendancePunch::TYPES)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'photo' => ['nullable', 'image', 'max:5120'], // 5 MB selfie
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
