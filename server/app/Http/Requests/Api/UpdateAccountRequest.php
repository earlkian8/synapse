<?php

namespace App\Http\Requests\Api;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The person's own name and email address, from the mobile app (ADR 0082) — the
 * same rules as the web's Settings → Profile.
 *
 * One more than the web asks: **a new email address needs the current
 * password.** An app session never expires, so without it whoever picks up an
 * unlocked phone could move the account to their own address and then reset the
 * password from there.
 */
class UpdateAccountRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->profileRules($this->user()->id),
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->changesEmail()),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Enter your current password to change your email address.',
            'current_password.current_password' => 'That isn’t your current password.',
        ];
    }

    /**
     * Whether the address asked for differs from the one on the account.
     */
    private function changesEmail(): bool
    {
        return mb_strtolower(trim((string) $this->input('email'))) !== mb_strtolower((string) $this->user()->email);
    }
}
