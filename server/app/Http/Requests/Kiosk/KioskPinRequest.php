<?php

namespace App\Http\Requests\Kiosk;

use App\Models\EmployeeKioskPin;
use App\Support\Attendance\KioskPins;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The PIN somebody chooses for themselves at a kiosk (ADR 0092), typed twice.
 * Whether it is too easy to guess is {@see KioskPins}'s call.
 */
class KioskPinRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['required', 'string', 'size:48'],
            'pin' => ['required', 'digits:'.EmployeeKioskPin::LENGTH, 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pin.required' => 'Choose a PIN.',
            'pin.digits' => 'A PIN is '.EmployeeKioskPin::LENGTH.' digits.',
            'pin.confirmed' => 'The two PINs are different. Type the same one twice.',
        ];
    }
}
