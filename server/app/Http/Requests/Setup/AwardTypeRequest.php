<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Create or update an award type (Company Setup → Award Types). The active flag is
 * normalised so an omitted switch reads as false.
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
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_active' => $this->boolean('is_active', true)]);
    }
}
