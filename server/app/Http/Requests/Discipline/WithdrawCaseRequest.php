<?php

namespace App\Http\Requests\Discipline;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Why a case is dropped before it is decided (ADR 0105).
 */
class WithdrawCaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'withdrawn_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'withdrawn_reason.required' => 'Say why the case is dropped.',
        ];
    }
}
