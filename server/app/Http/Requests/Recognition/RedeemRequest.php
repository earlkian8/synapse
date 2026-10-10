<?php

namespace App\Http\Requests\Recognition;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Ask for a reward, with an optional note for HR (a size, a colour).
 */
class RedeemRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['nullable', 'string', 'max:500']];
    }
}
