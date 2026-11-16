<?php

namespace App\Http\Requests\Attendance;

use App\Http\Controllers\Attendance\QrWebScanController;
use App\Models\EmployeeKioskPin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saying who is scanning a QR station in a phone's browser (ADR 0103): the claim
 * the opened page holds, the employee number and kiosk PIN, and where the
 * phone is. Judged by {@see QrWebScanController::identify()}.
 */
class QrWebIdentifyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'claim' => ['required', 'string', 'size:48'],
            'number' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'digits:'.EmployeeKioskPin::LENGTH],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number.required' => 'Type your employee number.',
            'pin.required' => 'Type your PIN.',
            'pin.digits' => 'Your PIN is '.EmployeeKioskPin::LENGTH.' digits.',
        ];
    }
}
