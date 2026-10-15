<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An employee number and PIN at a kiosk (ADR 0092). Kept loose on purpose:
 * whether they match is the one answer the kiosk gives, in one sentence, so the
 * shape of a PIN is not checked here.
 */
class KioskSignInRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'max:40'],
            'pin' => ['required', 'string', 'max:12'],
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
        ];
    }
}
