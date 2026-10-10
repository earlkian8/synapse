<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update an award type (Company Setup → Award Types). The active and
 * nominations flags are normalised so an omitted switch reads as on, and an
 * empty points field as none.
 */
class AwardTypeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'color' => ['nullable', 'string', 'max:30'],
            // ADR 0071: what an award of the type adds to the recipient's points,
            // and whether colleagues can nominate for it.
            'points' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'accepts_nominations' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'accepts_nominations' => $this->boolean('accepts_nominations', true),
            'points' => $this->filled('points') ? $this->input('points') : 0,
        ]);
    }
}
