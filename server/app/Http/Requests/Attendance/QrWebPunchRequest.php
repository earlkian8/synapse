<?php

namespace App\Http\Requests\Attendance;

use App\Http\Controllers\Attendance\QrWebScanController;
use App\Models\AttendancePunch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A punch at a QR station from a phone's browser (ADR 0103): the ticket, the
 * kind of punch, and the photo when the person's policy asks for one.
 * {@see QrWebScanController::punch()}.
 */
class QrWebPunchRequest extends FormRequest
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
