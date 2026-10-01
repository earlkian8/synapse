<?php

namespace App\Http\Requests\Auth;

use App\Support\EmailVerificationCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The one-time code typed on the verification screen.
 *
 * Shape only — whether the code is *this user's* current one is a credential
 * check, and lives with the credential in {@see EmailVerificationCode}.
 */
class VerifyEmailCodeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'digits:'.EmailVerificationCode::LENGTH],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.required' => 'Enter the code we emailed you.',
            'code.digits' => 'The code is '.EmailVerificationCode::LENGTH.' digits.',
        ];
    }

    /**
     * Accept a pasted code with the spaces the email prints it with.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => EmailVerificationCode::normalize($this->input('code'))]);
    }
}
