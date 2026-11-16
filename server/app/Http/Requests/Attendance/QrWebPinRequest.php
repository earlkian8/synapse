<?php

namespace App\Http\Requests\Attendance;

use App\Http\Controllers\Attendance\QrWebScanController;
use App\Models\EmployeeKioskPin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Choosing one's own PIN at a QR station, in a phone's browser (ADR 0103), with
 * the ticket the number and HR's PIN bought. {@see QrWebScanController::pin()}.
 */
class QrWebPinRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:48'],
            'pin' => ['required', 'string', 'digits:'.EmployeeKioskPin::LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.digits' => 'A PIN is '.EmployeeKioskPin::LENGTH.' digits.',
        ];
    }
}
