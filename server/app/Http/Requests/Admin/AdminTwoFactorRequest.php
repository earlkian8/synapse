<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The second sign-in step: the challenge the password earned, and a six-digit
 * authenticator code.
 */
class AdminTwoFactorRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\s*\d{3}\s*\d{3}\s*$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'challenge.*' => 'Your sign-in timed out. Sign in again.',
            'code.regex' => 'Enter the six-digit code from your authenticator app.',
        ];
    }
}
