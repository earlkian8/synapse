<?php

namespace App\Http\Requests\Admin;

use App\Models\Admin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new admin account (ADR 0085). No password field: the portal issues a
 * one-time password, so the creator never chooses — or knows — the real one.
 */
class StoreAdminRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('admins', 'email')->ignore($this->ignoredId())],
            'role' => ['required', 'string', Rule::in(array_keys(Admin::ROLES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another admin already uses this email.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
        ]);
    }

    protected function ignoredId(): ?int
    {
        return null;
    }
}
