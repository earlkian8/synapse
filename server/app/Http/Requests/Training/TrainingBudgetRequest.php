<?php

namespace App\Http\Requests\Training;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Set the organisation's training budget for a calendar year (ADR 0075).
 */
class TrainingBudgetRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
