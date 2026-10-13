<?php

namespace App\Http\Requests\Admin;

use App\Support\Admin\AdminPasswords;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An admin choosing their own password — the first thing they do after signing
 * in with one somebody else issued.
 */
class AdminPasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:admin'],
            'password' => ['required', 'string', 'max:255', 'confirmed', 'different:current_password', AdminPasswords::rule()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => 'That is not your current password.',
            'password.different' => 'Your new password must be different from your current one.',
        ];
    }
}
