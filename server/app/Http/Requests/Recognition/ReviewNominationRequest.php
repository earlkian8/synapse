<?php

namespace App\Http\Requests\Recognition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve a nomination (an edited citation and a date, both optional) or turn
 * it down (an optional note for the nominator).
 */
class ReviewNominationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'citation' => ['nullable', 'string', 'max:2000'],
            'awarded_on' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
