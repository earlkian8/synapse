<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Decline a request to join a program (ADR 0074), with an optional note the
 * person is shown.
 */
class DeclineEnrollmentRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
