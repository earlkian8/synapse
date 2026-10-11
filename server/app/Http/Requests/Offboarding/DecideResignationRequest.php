<?php

namespace App\Http\Requests\Offboarding;

use App\Support\TenantRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR's answer to a resignation (ADR 0076): accept it — with the last working
 * day agreed and, optionally, the clearance template to start from — or decline
 * it, saying why.
 */
class DecideResignationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', Rule::in(['accept', 'decline'])],
            'last_working_day' => ['required_if:decision,accept', 'nullable', 'date'],
            'offboarding_program_id' => ['nullable', 'integer', TenantRule::exists('offboarding_programs', 'id')->where('is_active', true)],
            'note' => ['required_if:decision,decline', 'nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'last_working_day.required_if' => 'Set the last working day you agreed.',
            'note.required_if' => 'Say why, so they know what to do next.',
        ];
    }
}
