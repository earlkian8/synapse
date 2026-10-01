<?php

namespace App\Http\Requests\Attendance;

use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A clock punch (in / out / break) from web self-service or the mobile API. The
 * capture context — GPS coordinates, accuracy and an optional selfie — is all
 * optional; only the punch `type` is required. Whether a selfie or a position is
 * *needed* is the attendance policy's call, made by the punch engine.
 *
 * The mobile app also sends what makes a punch it queued offline safe to replay
 * (ADR 0040): `client_id`, its own id for the punch, so a resend is recognised;
 * `punched_at`, the phone's time when the punch was made; and `sent_at`, the
 * phone's time when it sent it, so a wrong clock can be caught. The web ignores
 * all three.
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
            'client_id' => ['nullable', 'string', 'max:100'],
            'punched_at' => ['nullable', 'date'],
            'sent_at' => ['nullable', 'date', 'required_with:punched_at'],
        ];
    }
}
