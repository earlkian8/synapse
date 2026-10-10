<?php

namespace App\Http\Requests\Recognition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Add or edit a reward. An empty stock means as many as are asked for. The
 * active flag is normalised so an omitted switch reads as offered.
 */
class RewardRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'cost' => ['required', 'integer', 'min:1', 'max:1000000'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active' => $this->boolean('is_active', true),
            'stock' => $this->input('stock') === '' ? null : $this->input('stock'),
        ]);
    }
}
