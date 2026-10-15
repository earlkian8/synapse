<?php

namespace App\Http\Requests\Kiosk;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A ticket a kiosk hands back when somebody walks away (ADR 0092).
 */
class KioskTicketRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'ticket' => ['nullable', 'string', 'max:64'],
        ];
    }
}
